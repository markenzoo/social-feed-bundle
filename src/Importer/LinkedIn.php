<?php

declare(strict_types=1);

/*
 * social feed bundle for Contao Open Source CMS
 *
 * Copyright (c) 2024 pdir / digital agentur // pdir GmbH
 *
 * @package    social-feed-bundle
 * @link       https://github.com/pdir/social-feed-bundle
 * @license    http://www.gnu.org/licences/lgpl-3.0.html LGPL
 * @author     Mathias Arzberger <develop@pdir.de>
 * @author     Philipp Seibt <develop@pdir.de>
 * @author     pdir GmbH <https://pdir.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pdir\SocialFeedBundle\Importer;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Dbafs;
use Contao\File;
use Contao\FilesModel;
use Contao\NewsModel;
use Contao\System;
use LinkedIn\Client;
use LinkedIn\Exception;
use Pdir\SocialFeedBundle\Model\SocialFeedModel;
use Psr\Log\LogLevel;

class LinkedIn
{
    /**
     * Latest LinkedIn Marketing API version that is still supported.
     * Sunset versions are rejected, so this has to stay inside the one-year window.
     */
    private const API_VERSION = '202609';

    public int $counter = 0;
    public bool $poorManCron = false;
    private int $maxPosts = 100;
    private bool $debug = false;
    private bool $ignoreInterval = false;

    /**
     * @throws Exception
     * @throws \Exception
     */
    public function import(): bool
    {
        $logger = System::getContainer()->get('monolog.logger.contao');

        if (!$this->poorManCron) {
            $objSocialFeed = SocialFeedModel::findBy('socialFeedType', 'LinkedIn');
        } else {
            $objSocialFeed = SocialFeedModel::findBy(
                ['socialFeedType = ?', 'pdir_sf_fb_news_cronjob != ?'],
                ['LinkedIn', 'no_cronjob']
            );
        }

        if ($this->debug) {
            dump($objSocialFeed);
        }

        if (null === $objSocialFeed) {
            if ($this->debug) {
                dump('--- no LinkedIn social feed account available!');
            }

            return false;
        }

        $this->counter = 0;

        foreach ($objSocialFeed as $account) {
            $this->counter = 0;
            $cron = $account->pdir_sf_fb_news_cronjob;
            $lastImport = $account->pdir_sf_fb_news_last_import_date;

            if ($this->poorManCron) {
                $this->maxPosts = $account->number_posts;
            }

            if ('' === $lastImport) {
                $lastImport = 0;
            }
            $interval = time() - $lastImport;

            if (($interval >= $cron && 'no_cronjob' !== $cron) || (0 === $lastImport && 'no_cronjob' !== $cron) || true === $this->ignoreInterval) {
                NewsImporter::setLastImportDate($account);

                $client = new Client(
                    $account->linkedin_client_id,
                    $account->linkedin_client_secret
                );

                $client->setAccessToken($account->linkedin_access_token);
                $client->setApiRoot('https://api.linkedin.com/rest/');

                // Posts finder. ugcPosts and version 202306 are no longer accepted.
                $client->setApiHeaders([
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'LinkedIn-Version' => self::API_VERSION,
                    'X-RestLi-Method' => 'FINDER',
                ]);

                $count = max(1, min(100, $this->maxPosts));
                $author = rawurlencode('urn:li:organization:'.$account->linkedin_company_id);
                $posts = $client->get(
                    'posts?q=author&author='.$author.'&sortBy=LAST_MODIFIED&count='.$count
                );

                if (!\is_array($posts['elements'] ?? null)) {
                    continue;
                }

                // Image lookups are plain GETs, not the posts finder.
                $client->setApiHeaders([
                    'Content-Type' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'LinkedIn-Version' => self::API_VERSION,
                ]);

                foreach ($posts['elements'] as $element) {
                    $objFile = null;

                    if ($this->debug) {
                        dump('-- LinkedIn API: element data');
                        dump($element);
                    }

                    if (!isset($element['id']) || (isset($element['lifecycleState']) && 'PUBLISHED' !== $element['lifecycleState'])) {
                        continue;
                    }

                    // @todo import shared content only if wanted by user $account->linkedinImportSharedContent

                    // continue if news exists
                    if (null !== NewsModel::findBy('social_feed_id', $element['id'])) {
                        if ($this->debug) {
                            dump('ignore existing post '.$element['id']);
                        }
                        continue;
                    }

                    $item = [];
                    $firstImage = $this->imageDownloadUrl($client, $element);

                    if (null !== $firstImage) {
                        $imgPath = NewsImporter::createImageFolder($account->linkedin_company_id);
                        $picturePath = $imgPath.$this->postFileId((string) $element['id']).'.jpg';

                        // get first image
                        if (!file_exists($picturePath)) {
                            // Write to filesystem
                            $file = new File($picturePath);
                            $file->write(file_get_contents($firstImage));
                            $file->close();

                            // Add the resource
                            $objFile = Dbafs::addResource($picturePath);
                        }

                        // get files model for existing image
                        if (file_exists($picturePath) && null === $objFile) {
                            $objFile = FilesModel::findByPath($picturePath);
                        }
                    }

                    $text = $this->plainCommentary((string) ($element['commentary'] ?? ''));
                    $publishedAt = (int) ($element['publishedAt'] ?? $element['createdAt'] ?? 0);

                    $item['id'] = $element['id'];
                    $item['headline'] = NewsImporter::shortenHeadline($text);
                    $item['teaser'] = str_replace("\n", '<br>', $text);
                    $item['singleSRC'] = null !== $objFile ? $objFile->uuid : '';
                    $item['date'] = (int) ($publishedAt / 1000);
                    $item['time'] = (int) ($publishedAt / 1000);
                    $item['permalink'] = 'https://www.linkedin.com/feed/update/'.$item['id'].'/';

                    // @todo get organization and set account picture
                    // $item['social_feed_account'] = $organization?? $organization['localizedName'];

                    if ($this->debug) {
                        dump('-- Post data for import');
                        dump($item);
                    }

                    // write to db
                    $importer = new NewsImporter();
                    $importer->setNews($item);
                    $importer->execute($account->pdir_sf_fb_news_archive, $account);

                    ++$this->counter;
                }

                if (0 < $this->counter) {
                    $logger->log(LogLevel::INFO, 'Social Feed (ID '.$account->id.'): LinkedIn - imported '.$this->counter.' items.', ['contao' => new ContaoContext(__METHOD__, 'INFO')]);
                }
            }

            if (0 === $this->counter) {
                $logger->log(LogLevel::INFO, 'Social Feed (ID '.$account->id.'): LinkedIn Import - nothing to import', ['contao' => new ContaoContext(__METHOD__, 'INFO')]);
            }
        }

        return true;
    }

    public function setPoorManCronMode($flag): void
    {
        if (true === $flag) {
            $this->poorManCron = true;
        }
    }

    public function setDebugMode($flag): void
    {
        if (true === $flag) {
            $this->debug = true;
        }
    }

    public function setIgnoreInterval($flag): void
    {
        if (true === $flag) {
            $this->ignoreInterval = true;
        }
    }

    public function setMaxPosts($maxPosts): void
    {
        $this->maxPosts = $maxPosts;
    }

    /**
     * Posts no longer include a direct image URL. Resolve the first image URN.
     */
    private function imageDownloadUrl(Client $client, array $element): ?string
    {
        $content = $element['content'] ?? null;

        if (!\is_array($content)) {
            return null;
        }

        $urn = $content['media']['id'] ?? $content['multiImage']['images'][0]['id'] ?? $content['article']['thumbnail'] ?? null;

        if (!\is_string($urn) || !str_starts_with($urn, 'urn:li:image:')) {
            return null;
        }

        try {
            $image = $client->get('images/'.rawurlencode($urn));
        } catch (Exception $e) {
            if ($this->debug) {
                dump($e->getMessage());
            }

            return null;
        }

        $downloadUrl = $image['downloadUrl'] ?? null;

        return \is_string($downloadUrl) && '' !== $downloadUrl ? $downloadUrl : null;
    }

    /**
     * Commentary uses LinkedIn "little" text (mentions and hashtags).
     */
    private function plainCommentary(string $commentary): string
    {
        $text = preg_replace('/\{hashtag\|[^|]*\|([^}]+)\}/', '#$1', $commentary) ?? $commentary;
        $text = preg_replace('/@\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;

        return $text;
    }

    private function postFileId(string $id): string
    {
        $part = str_contains($id, ':') ? substr((string) strrchr($id, ':'), 1) : $id;

        return '' !== $part ? $part : $id;
    }
}
