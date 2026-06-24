<?php


namespace App;

use Bolt\Common\Json;
use Bolt\Common\Str;
use Bolt\Entity\Content;
use Bolt\Enum\Statuses;
use Bolt\Extension\BaseExtension;
use Bolt\Repository\ContentRepository;
use Illuminate\Support\Collection;
use Symfony\Component\HttpClient\HttpClient;

class PackagistExtension extends BaseExtension
{
    private const PACKAGIST_LIST = 'https://packagist.org/packages/list.json';
    private const PACKAGIST_DETAIL = 'https://packagist.org/packages/';
    private const TYPE_EXTENSION = 'bolt-extension';
    private const TYPE_THEME = 'bolt-theme';
    private const PACKAGE_NAME_PATTERN = '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/';
    // Upper bound on packages refreshed per `app:update` run. Sized to comfortably
    // cover the full Bolt extension + theme registry (~90 packages) with headroom,
    // so a single run updates everything. Acts as a safety cap as the registry grows.
    private const MAX_COUNT = 500;
    private $updated = [];

    public function getName(): string
    {
        return "Packagist API fetcher";
    }

    public function initialize(): void
    {

    }

    public function fetchPackages(?string $type): void
    {
        $type = $this->normalizePackageType($type);

        $url = sprintf('%s?type=%s', self::PACKAGIST_LIST, $type);
        $client = HttpClient::create();
        $response = $client->request('GET', $url, ['timeout' => 20]);
        $packages = Json::json_decode($response->getContent());

        if (!isset($packages->packageNames) || !is_array($packages->packageNames)) {
            throw new \UnexpectedValueException('Packagist list response is missing packageNames.');
        }

        $om = $this->getObjectManager();
        /** @var ContentRepository $contentRepository */
        $contentRepository = $om->getRepository(Content::class);

        foreach ($packages->packageNames as $package) {
            if (!is_string($package) || !$this->isValidPackageName($package)) {
                echo "Skip invalid package name from Packagist list\n";
                continue;
            }

            $record = $contentRepository->findOneByFieldValue('packagist_name', $package);

            if (!$record) {
                echo "Add new stub: $package \n";
                $this->insertPackageStub($package, $type);
            } else {
                echo "Already have: $package \n";
            }
        }
    }


    private function insertPackageStub(string $package, string $type)
    {
        $om = $this->getObjectManager();

        $contentTypeDefinition = $this->getBoltConfig()->getContentType('packages');
        $content = new Content($contentTypeDefinition);

        $content->setFieldValue('packagist_name', $package);
        $content->setFieldValue('title', $package);
        $content->setFieldValue('slug', str_replace('/', '-', $package));
        $content->setFieldValue('packagist_type', $type);
        $content->setModifiedAt(new \DateTime('last year'));

        $om->persist($content);
        $om->flush();
    }

    public function updatePackages(string $name = null): array
    {
        $om = $this->getObjectManager();
        $client = HttpClient::create();
        $count = 0;

        // Without an explicit limit, getContentForTwig() caps the result set at
        // the ContentType's records_per_page (50 for 'packages'), which silently
        // defeats the MAX_COUNT batch size below and leaves newer packages stuck
        // as unpublished stubs. Ask for the full batch we intend to process.
        $params = ['order' => 'modifiedAt', 'status' => '!unknown', 'limit' => self::MAX_COUNT];

        if ($name) {
            if (!$this->isValidPackageName($name)) {
                throw new \InvalidArgumentException(sprintf('Invalid package name "%s".', $name));
            }

            $params['packagist_name'] = $name;
        }

        $records = $this->getQuery()->getContentForTwig('packages', $params);

        /** @var Content $record */
        foreach ($records as $record) {
            if ($count++ >= self::MAX_COUNT) {
                break;
            }

            $packagistName = (string) $record->getFieldValue('packagist_name');

            if (!$this->isValidPackageName($packagistName)) {
                $record->setStatus(Statuses::HELD);
                $this->updated[] = [$packagistName, '-', 'invalid package name'];
                $om->persist($record);
                continue;
            }

            $url = sprintf('%s%s.json', self::PACKAGIST_DETAIL, $packagistName);

            try {
                $response = $client->request('GET', $url, ['timeout' => 20]);
                $responseArray = current($response->toArray());

                if (!is_array($responseArray)) {
                    throw new \UnexpectedValueException('Packagist detail response has an unexpected shape.');
                }

                // The detail endpoint (packages/{name}.json) already contains the
                // full version map under "versions", keyed by version string. This
                // is the same shape the now-retired repo.packagist.org/p/{name}.json
                // endpoint used to return (which now responds with HTTP 403), so we
                // re-wrap it keyed by package name to keep the downstream logic
                // unchanged instead of making a second, failing HTTP request.
                $versionsArray = [$packagistName => $responseArray['versions'] ?? []];

                $this->updateRecord($record, $responseArray, $versionsArray, $packagistName);

            } catch (\Throwable $exception) {
                error_log(sprintf('Could not update %s: %s', $url, $exception->getMessage()));
                $record->setStatus(Statuses::HELD);
                $this->updated[] = [$packagistName, '-', 'held'];
            }


            $om->persist($record);
        }

        $om->flush();

        return $this->updated;
    }

    private function updateRecord(Content $record, array $responseArray, array $versionsArray, string $packagistName): void
    {
        $package = new Collection($responseArray);

        $record->setFieldValue('description', $package->get('description'));
        $record->setFieldValue('time', $package->get('time'));
        $record->setFieldValue('maintainers', $package->get('maintainers'));
        $record->setFieldValue('packagist_type', $package->get('type'));
        $record->setFieldValue('repository', $this->sanitizeRepositoryUrl($package->get('repository')));
        $record->setFieldValue('github_stars', (int) $package->get('github_stars', 0));

        $downloads = $package->get('downloads', []);
        $record->setFieldValue('downloads_total', (int) ($downloads['total'] ?? 0));
        $record->setFieldValue('downloads_monthly', (int) ($downloads['monthly'] ?? 0));
        $record->setFieldValue('downloads_daily', (int) ($downloads['daily'] ?? 0));
        $record->setFieldValue('favers', (int) $package->get('favers', 0));

        $record->setModifiedAt(new \DateTime());

        $versions = $this->sortVersions($versionsArray);

        if ($versions === []) {
            throw new \UnexpectedValueException(sprintf('Package "%s" has no versions.', $packagistName));
        }

        $record->setFieldValue('versions', $versions);

        $latest_version = (new Collection(current($versionsArray)))->get(current($versions));

        if (!is_array($latest_version)) {
            throw new \UnexpectedValueException(sprintf('Package "%s" latest version metadata is missing.', $packagistName));
        }

        if (isset($latest_version['require']['bolt/core'])) {
            $record->setFieldValue('required_version', $latest_version['require']['bolt/core']);
            $record->setFieldValue('require', $latest_version['require']);
        } else {
            $record->setFieldValue('required_version', 3);
        }

        if (isset($latest_version['extra']['screenshots'])) {
            $record->setFieldValue('screenshots', $this->sanitizeScreenshots($latest_version['extra']['screenshots']));
        } else {
            $record->setFieldValue('screenshots', []);
        }

        $record->setFieldValue('time_updated', $latest_version['time'] ?? $package->get('time'));
        $record->setStatus(Statuses::PUBLISHED);

        if (($latest_version['version'] ?? null) === 'dev-master') {
            $record->setStatus(Statuses::DRAFT);
        }

        // @todo This is hackish. Make better.
        if (in_array($packagistName, [
            "wemakecustom/bolt-parent-theme",
            "ggioffreda/bolt-extension-rollbar",
            "gigabit/twig-wrap",
            "goodbytes/readtime",
            "ornito/rest-create-users",
            "zillingen/json-content",
            "zillingen/json-files",
        ])) {
            $record->setStatus(Statuses::DRAFT);
        }

        $this->updated[] = [ $packagistName, $latest_version['version'] ?? current($versions), $record->getStatus() ];
    }

    private function sortVersions(array $versionsArray): array
    {
        $rawVersions = (new Collection(current($versionsArray)))->keys()->all();
        $versions = [];

        foreach ($rawVersions as $version) {
            $key = $version;
            if (Str::startsWith($key, 'v')) {
                $key = Str::removeFirst($key, 'v');
            }
            $versions[$key] = $version;
        }

        uksort($versions, 'version_compare');

        return array_reverse($versions);
    }

    private function normalizePackageType(?string $type): string
    {
        if ($type === 'extension' || $type === '1' || $type === self::TYPE_EXTENSION) {
            return self::TYPE_EXTENSION;
        }

        if ($type === 'theme' || $type === '2' || $type === self::TYPE_THEME) {
            return self::TYPE_THEME;
        }

        throw new \InvalidArgumentException(sprintf('Invalid package type "%s". Expected "extension" or "theme".', $type));
    }

    private function isValidPackageName(string $package): bool
    {
        return preg_match(self::PACKAGE_NAME_PATTERN, $package) === 1;
    }

    private function sanitizeRepositoryUrl($repository): ?string
    {
        if (!is_string($repository)) {
            return null;
        }

        $repository = trim($repository);
        $scheme = parse_url($repository, PHP_URL_SCHEME);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $repository;
    }

    private function sanitizeScreenshots($screenshots): array
    {
        if (!is_array($screenshots)) {
            $screenshots = [$screenshots];
        }

        return array_values(array_filter($screenshots, static function ($screenshot): bool {
            if (!is_string($screenshot)) {
                return false;
            }

            if (str_contains($screenshot, '://') || str_starts_with($screenshot, '/') || str_contains($screenshot, '..')) {
                return false;
            }

            return preg_match('/^[A-Za-z0-9._\/-]+\.(?:gif|jpe?g|png|webp)$/i', $screenshot) === 1;
        }));
    }
}
