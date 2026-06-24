<?php


namespace App;

use Bolt\Common\Json;
use Bolt\Common\Str;
use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use Bolt\Enum\Statuses;
use Bolt\Extension\BaseExtension;
use Bolt\Repository\ContentRepository;
use Bolt\Storage\Query;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\HttpClient;
use Tightenco\Collect\Support\Collection;

class PackagistExtension extends BaseExtension
{
    private const PACKAGIST_LIST = 'https://packagist.org/packages/list.json';
    private const PACKAGIST_DETAIL = 'https://packagist.org/packages/';
    private const TYPE_EXTENSION = 'bolt-extension';
    private const TYPE_THEME = 'bolt-theme';
    // Upper bound on packages refreshed per `app:update` run. Sized to comfortably
    // cover the full Bolt extension + theme registry (~90 packages) with headroom,
    // so a single run updates everything. Acts as a safety cap as the registry grows.
    private const MAX_COUNT = 500;
    private $updated = [];

    /** @var ContentRepository */
    private $contentRepository;

    public function getName(): string
    {
        return "Packagist API fetcher";
    }

    public function initialize(): void
    {

    }

    public function fetchPackages(string $type): void
    {
        if ($type === 'extension' || $type === '1') {
            $type = self::TYPE_EXTENSION;
        } else {
            $type = self::TYPE_THEME;
        }

        $url = sprintf('%s?type=%s', self::PACKAGIST_LIST, $type);
        $client = HttpClient::create();
        $response = $client->request('GET', $url);

        $json = $response->getContent();
        $packages = Json::json_decode($json);

        $om = $this->getObjectManager();
        /** @var ContentRepository $contentRepository */
        $contentRepository = $om->getRepository(Content::class);

        foreach ($packages->packageNames as $package) {
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
        $content->setFieldValue('slug', $package);
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
            $params['packagist_name'] = $name;
        }

        $records = $this->getQuery()->getContentForTwig('packages', $params);

        /** @var Content $record */
        foreach ($records as $record) {
            if ($count++ >= self::MAX_COUNT) {
                break;
            }

            $packagistName = (string) $record->getFieldValue('packagist_name');

            $url = sprintf('%s%s.json', self::PACKAGIST_DETAIL, $packagistName);

            try {
                $response = $client->request('GET', $url);
                $responseArray = current($response->toArray());

                // The detail endpoint (packages/{name}.json) already contains the
                // full version map under "versions", keyed by version string. This
                // is the same shape the now-retired repo.packagist.org/p/{name}.json
                // endpoint used to return (which now responds with HTTP 403), so we
                // re-wrap it keyed by package name to keep the downstream logic
                // unchanged instead of making a second, failing HTTP request.
                $versionsArray = [$packagistName => $responseArray['versions'] ?? []];

                $this->updateRecord($record, $responseArray, $versionsArray, $packagistName);

            } catch (ClientException $exception) {
                dump('Could not get ' . $url);
                $record->setStatus('held');
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
        $record->setFieldValue('repository', $package->get('repository'));
        $record->setFieldValue('github_stars', $package->get('github_stars'));
        $record->setFieldValue('downloads_total', $package->get('downloads')['total']);
        $record->setFieldValue('downloads_monthly', $package->get('downloads')['monthly']);
        $record->setFieldValue('downloads_daily', $package->get('downloads')['daily']);
        $record->setFieldValue('favers', $package->get('favers'));

        $record->setModifiedAt(new \DateTime());

        $versions = $this->sortVersions($versionsArray);

        $record->setFieldValue('versions', $versions);

        $latest_version = (new Collection(current($versionsArray)))->get(current($versions));

        if (isset($latest_version['require']['bolt/core'])) {
            $record->setFieldValue('required_version', $latest_version['require']['bolt/core']);
            $record->setFieldValue('require', $latest_version['require']);
        } else {
            $record->setFieldValue('required_version', 3);
        }

        if (isset($latest_version['extra']['screenshots'])) {
            $record->setFieldValue('screenshots', $latest_version['extra']['screenshots']);
        } else {
            $record->setFieldValue('screenshots', []);
        }

        $record->setFieldValue('time_updated', $latest_version['time']);
        $record->setStatus(Statuses::PUBLISHED);

        if ($latest_version['version'] === 'dev-master') {
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

        $this->updated[] = [ $packagistName, $latest_version['version'], $record->getStatus() ];
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
}