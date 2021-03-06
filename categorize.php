<?php

define('DS', DIRECTORY_SEPARATOR);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require __DIR__ . DS . 'vendor' . DS . 'autoload.php';

$cdir = __DIR__;
if (substr(__DIR__, 0, 4) === 'phar') {
    $cdir = dirname(str_replace('phar://', '', __DIR__));
}

$postsCache = [];
$md5Cache = [];
$client = null;
$lastRequest = 0;
$buffer = '';
$waitLastTime = 0;
$waitAnimChar = '|';

if (file_exists($cdir . '/posts.json')) {
    ini_set('memory_limit', '4G');
    $loadedPostsJson = true;

    echo 'Loading posts.json...' . PHP_EOL;

    $data = file_get_contents($cdir . '/posts.json');
    $postsCache = json_decode($data, true);
    unset($data);

    echo 'Building initial MD5 lookup cache...' . PHP_EOL;

    foreach ($postsCache as $post) {
        if (!isset($md5Cache[$post['file']['md5']])) {
            $md5Cache[$post['file']['md5']] = $post['id'];
        }
    }

    echo 'Peak memory usage: ' . round(memory_get_peak_usage() / 1048576, 2) . ' MB' . PHP_EOL;
}

/**
 * @param array $data
 * @param bool  $isRetry
 *
 * @return array
 */
function getPosts(array $data = [], $isRetry = false)
{
    global $client, $lastRequest, $postsCache;

    if (isset($data['tags'])) {
        $tags = trim(preg_replace('!\s+!', ' ', $data['tags']));

        if (substr_count($tags, ' ') + 1 > 6) {
            return ['error' => 'You can only search up to 6 tags.'];
        }

        $tagsExploded = explode(' ', $tags);

        foreach ($tagsExploded as $explodedTag) {
            if (strpos($explodedTag, 'id:') !== false) {
                $post_id = substr($explodedTag, 3);

                if (isset($postsCache[$post_id])) {
                    return [
                        'posts' => [
                            $postsCache[$post_id],
                        ],
                    ];
                }
            }
        }
    }

    try {
        if ($lastRequest === time()) {
            sleep(1);
        }

        /** @var Client $client */
        $response = $client->request('GET', 'posts.json', ['query' => $data]);
        $lastRequest = time();

        $result = json_decode((string)$response->getBody(), true);

        if (!is_array($result)) {
            return ['error' => 'Data received from e621.net API is invalid'];
        }

        if (isset($result['posts'])) {
            foreach ($result['posts'] as $post) {
                $post_id = $post['id'];

                if (!isset($postsCache[$post_id])) {
                    $postsCache[$post_id] = $post;
                }
            }
        }

        return $result;
    } catch (Exception $e) {
        if (
            $isRetry === false &&
            $e instanceof RequestException &&
            ($response = $e->getResponse()) &&
            $response->getStatusCode() === 429
        ) {
            sleep(1);
            return getPosts($data, true);
        }

        return ['error' => 'Exception: ' . PHP_EOL . $e->getMessage()];
    }
}

/**
 * @param $md5
 *
 * @return int|bool|null
 */
function getPostIdByMd5($md5)
{
    global $md5Cache;

    if (isset($md5Cache[$md5])) {
        return $md5Cache[$md5];
    }

    $result = getPosts(['tags' => 'md5:' . $md5 . ' status:any']);

    if (is_array($result)) {
        if (isset($result['posts'][0]['id'])) {
            return $result['posts'][0]['id'];
        }

        return false;
    }

    echo 'API failure: ' . ($result['error'] ?: 'Unknown error') . PHP_EOL;

    return null;
}

/**
 * @param array $md5s
 *
 * @return bool|null
 */
function batchMd5(array $md5s)
{
    global $md5Cache;

    $result = getPosts(['tags' => 'md5:' . implode(',', $md5s) . ' status:any']);

    if (is_array($result) && isset($result['posts'])) {
        foreach ($result['posts'] as $post) {
            if (isset($post['id'])) {
                $md5Cache[$post['file']['md5']] = $post['id'];
            }
        }

        return true;
    }

    echo 'API failure: ' . ($result['error'] ?: 'Unknown error') . PHP_EOL;

    return null;
}

/**
 * @param int $post_id
 *
 * @return bool|null
 */
function getPostExists($post_id)
{
    if (isset($postsCache[$post_id])) {
        return true;
    }

    $result = getPosts(['tags' => 'id:' . $post_id]);

    if (is_array($result)) {
        if (isset($result['posts'][0]['id'])) {
            return true;
        }

        return false;
    }

    echo 'API failure: ' . ($result['error'] ?: 'Unknown error') . PHP_EOL;

    return null;
}

/**
 * @param int $post_id
 *
 * @return string|bool|null
 */
function getPostRating($post_id)
{
    $result = getPosts(['tags' => 'id:' . $post_id . ' status:any']);

    if (is_array($result)) {
        if (isset($result['posts'][0]['rating'])) {
            return $result['posts'][0]['rating'];
        }

        return false;
    }

    echo 'API failure: ' . ($result['error'] ?: 'Unknown error') . PHP_EOL;

    return null;
}

/**
 * @param int $post_id
 *
 * @return array|bool|null
 */
function getPostTags($post_id)
{
    $result = getPosts(['tags' => 'id:' . $post_id . ' status:any']);

    if (is_array($result)) {
        if (isset($result['posts'][0]['tags'])) {
            return $result['posts'][0]['tags'];
        }

        return false;
    }

    echo 'API failure: ' . ($result['error'] ?: 'Unknown error') . PHP_EOL;

    return null;
}

/**
 * @param $file
 *
 * @return false|resource
 */
function convertImage($file)
{
    if ((is_bool(CONVERT) && CONVERT === false) || (is_int(CONVERT) && CONVERT > filesize($file))) {
        return file_get_contents($file);
    }

    global $fileContentsCache;

    if ($fileContentsCache !== null) {
        return $fileContentsCache;
    }

    $mime_type = mime_content_type($file);

    if ($mime_type === 'image/png') {
        $image = @imagecreatefrompng($file);
    } elseif ($mime_type === 'image/jpeg') {
        $image = @imagecreatefromjpeg($file);
    } elseif ($mime_type === 'image/gif') {
        $image = @imagecreatefromgif($file);
    }

    if (isset($image) && $image !== false) {
        ob_start();
        imagejpeg($image, null, 90);
        $file_contents = ob_get_clean();

        imagedestroy($image);
        return $file_contents;
    }

    return null;
}

/**
 * @param string $file
 * @param bool   $isRetry
 *
 * @return array|bool
 */
function reverseSearch($file, $isRetry = false)
{
    global $client, $buffer, $lastRequest;

    try {
        $image = convertImage($file);

        if ($image === null) {
            echo "\r" . $buffer . ' file conversion failed';

            return false;
        }

        $request_options = [
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => $image,
                    'filename' => 'image.jpg',
                ],
            ],
            'progress'  => static function ($download_size = 0, $downloaded = 0, $upload_size = 0, $uploaded = 0) {
                global $buffer;
                $total = 0;
                $progress = 0;

                if ($download_size > 0) {
                    $total = $download_size;
                    $progress = $downloaded;
                } elseif ($upload_size > 0) {
                    $total = $upload_size;
                    $progress = $uploaded;
                }

                if ($total > 0) {
                    print (str_repeat(' ', 10) . "\r" . $buffer . ' ' . round(($progress * 100) / $total, 0)) . "% ";
                }
            },
        ];

        if ($lastRequest === time()) {
            sleep(1);
        }

        /** @var Client $client */
        $response = $client->request('GET', 'iqdb_queries.json', $request_options);
        $lastRequest = time();

        $raw_response = (string)$response->getBody();
        $json_result = json_decode($raw_response, true);

        if (is_array($json_result)) {
            if (count($json_result) > 0 && isset($json_result[0]['post_id'])) {
                $results = [];
                foreach ($json_result as $result) {
                    if (count($json_result) > 1 && isset($result['post']['posts']) && empty($result['post']['posts']['file_url']))
                        continue;
                        
                    $results[] = $result['post_id'];
                }

                echo "\r" . $buffer . ' success';

                return $results;
            }

            echo "\r" . $buffer . ' no results';

            return [];
        }

        echo "\r" . $buffer . ' response is empty or invalid';
    } catch (Exception $e) {
        if (
            $isRetry === false &&
            $e instanceof RequestException &&
            ($response = $e->getResponse()) &&
            $response->getStatusCode() === 429
        ) {
            echo "\r" . $buffer . ' throttled, retrying...' . PHP_EOL;

            sleep(1);
            return reverseSearch($file, true);
        }

        echo "\r" . $buffer . ' exception' . PHP_EOL . trim($e->getMessage());
    }

    return false;
}

/**
 * @param array $tags
 *
 * @return array
 */
function getTagsAsOneArray(array $tags)
{
    $tagsAsOneArray = [];
    foreach ($tags as $tags_category) {
        foreach ($tags_category as $tag) {
            if (!in_array($tags, $tagsAsOneArray, true)) {
                $tagsAsOneArray[] = $tag;
            }
        }
    }

    sort($tagsAsOneArray);

    return $tagsAsOneArray;
}

/**
 * @param array $tags
 *
 * @return string|array
 */
function checkForGenderTag(array $tags)
{
    $genderTags = [
        'male',
        'female',
        'andromorph'       => 'cuntboy',
        'gynomorph'        => 'dickgirl',
        'herm',
        'maleherm',
        'ambiguous_gender' => 'ambiguous',
    ];

    $found = [];
    foreach ($genderTags as $genderTag => $genderName) {
        if (is_numeric($genderTag)) {
            $genderTag = $genderName;
        }

        if (in_array($genderTag, $tags, true)) {
            $found[] = ucfirst($genderName);
        }
    }

    if (count($found) === 1) {
        return $found[0];
    }

    if (count($found) > 1) {
        return $found;
    }

    return '';
}

/**
 * @param array $tags
 *
 * @return string|array
 */
function checkForInteractionTag(array $tags)
{
    $genderTags = [
        'male',
        'female',
        'gynomorph'  => 'dickgirl',
        'andromorph' => 'cuntboy',
        'herm',
        'maleherm',
        'ambiguous',
    ];

    $sexActTags = [];
    foreach ($genderTags as $firstGender => $firstGenderCustomName) {
        if (is_numeric($firstGender)) {
            $firstGender = $firstGenderCustomName;
        }

        foreach ($genderTags as $secondGender => $secondGenderCustomName) {
            if (is_numeric($secondGender)) {
                $secondGender = $secondGenderCustomName;
            }

            $sexActTags[$firstGender . '/' . $secondGender] = ucfirst($firstGenderCustomName) . ' & ' . ucfirst($secondGenderCustomName);
        }
    }

    $hasGroupTag = false;
    foreach ($tags as $tag) {
        if ($tag === 'group') {
            $hasGroupTag = true;
        }
    }

    $found = [];
    foreach ($sexActTags as $sexActTag => $sexActName) {
        if (is_numeric($sexActTag)) {
            $sexActTag = $sexActName;
        }

        if (in_array($sexActTag, $tags, true)) {
            $sexActNameInverted = explode(' & ', $sexActName);
            $sexActNameInverted = $sexActNameInverted[1] . ' & ' . $sexActNameInverted[0];

            // Prevent posts where both variations of same tag exist from breaking stuff (eg. male/female + female/male)
            if (in_array($sexActNameInverted, $found, true)) {
                continue;
            }

            $found[] = ucfirst($sexActName);
        }
    }

    if ($hasGroupTag === false && count($found) === 1) {
        return $found[0];
    }

    if ($hasGroupTag === true) {
        if (count($found) === 1) {
            return 'Multiple characters' . DS . $found[0];
        }

        return 'Multiple characters';
    }

    // This usually means post was incorrectly tagged or is missing 'group' tag
    if (count($found) > 1) {
        return $found;
    }

    return '';
}

/**
 * @param string $file
 * @param string $post_id
 *
 * @return string
 */
function categorize($file, $post_id)
{
    global $debugData;

    $destinationSubdirectory = '';

    $postExists = getPostExists($post_id);

    if ($postExists !== true) {
        echo 'Post not found!' . PHP_EOL;

        return '! Not found';
    }

    if (!empty(REQUIRE_ALL_TAGS) || !empty(REQUIRE_ONE_TAG)) {
        $destinationSubdirectoryForMissingTags = '! Invalid';
        $tags = getPostTags($post_id);

        if (is_array($tags)) {
            $tagsAsOneArray = getTagsAsOneArray($tags);

            if (!empty(REQUIRE_ALL_TAGS)) {
                $requiredTags = explode(' ', REQUIRE_ALL_TAGS);

                foreach ($requiredTags as $requiredTag) {
                    if (!in_array($requiredTag, $tagsAsOneArray, true)) {

                        echo 'Missing all required tag(s)!' . PHP_EOL;

                        return $destinationSubdirectoryForMissingTags;
                    }
                }
            }

            if (!empty(REQUIRE_ONE_TAG)) {
                $requiredTags = explode(' ', REQUIRE_ONE_TAG);

                $found = false;
                foreach ($requiredTags as $requiredTag) {
                    if (in_array($requiredTag, $tagsAsOneArray, true)) {
                        $found = true;
                        break;
                    }
                }

                if ($found === false) {
                    echo 'Missing required tag(s)!' . PHP_EOL;

                    return $destinationSubdirectoryForMissingTags;
                }
            }
        }
    }

    if (BY_RATING === true) {
        $ratings = [
            'e' => 'Explicit',
            'q' => 'Questionable',
            's' => 'Safe',
        ];

        $rating = getPostRating($post_id);

        if (!empty($rating) && isset($ratings[$rating])) {
            $destinationSubdirectory = $ratings[$rating];

            echo 'Rating: ' . $destinationSubdirectory . PHP_EOL;
        } else {
            if (!empty($rating)) {
                echo 'Unknown rating value: ' . $rating . PHP_EOL;
            }

            echo 'Failed to categorize by rating' . PHP_EOL;

            return '! Unknown rating';
        }
    }

    if (BY_INTERACTION === true) {
        $interaction = 'Unknown';

        if (!isset($tagsAsOneArray)) {
            $tags = getPostTags($post_id);

            if (is_array($tags)) {
                $tagsAsOneArray = getTagsAsOneArray($tags);
            }
        }

        if (isset($tagsAsOneArray)) {
            if (in_array('solo', $tagsAsOneArray, true)) {
                echo 'Interaction: ' . ($interaction = 'Solo') . PHP_EOL;

                $gender = checkForGenderTag($tagsAsOneArray);

                if (is_array($gender)) {
                    $interaction .= DS . '! Conflict';

                    echo 'Conflict: ' . implode(', ', $gender) . PHP_EOL;

                    $debugData = 'Multiple gender tags matched: ' . PHP_EOL . implode(PHP_EOL, $gender);
                    $debugData .= PHP_EOL . PHP_EOL . 'Tags: ' . print_r($tagsAsOneArray, true);
                } elseif (!empty($gender)) {
                    $interaction .= DS . $gender;

                    echo 'Gender: ' . $gender . PHP_EOL;
                } else {
                    $interaction .= DS . 'Unknown';

                    echo 'Missing gender tag!' . PHP_EOL;
                }
            } else {
                $interactionTag = checkForInteractionTag($tagsAsOneArray);

                if (is_array($interactionTag)) {
                    $interaction = '! Conflict';

                    echo 'Conflict: ' . implode(', ', $interactionTag) . PHP_EOL;

                    $debugData = 'Multiple interaction tags matched: ' . PHP_EOL . implode(PHP_EOL, $interactionTag);
                    $debugData .= PHP_EOL . PHP_EOL . 'Tags: ' . print_r($tagsAsOneArray, true);
                } elseif (!empty($interactionTag)) {
                    $interaction = $interactionTag;

                    if (strpos($interaction, DS) === false && in_array('solo_focus', $tagsAsOneArray, true)) {
                        $interaction .= DS . 'Solo focus';

                        $gender = checkForGenderTag($tagsAsOneArray);

                        if (!is_array($gender) && !empty($gender)) {
                            $interaction .= DS . $gender;
                        }
                    }

                    echo 'Interaction: ' . $interaction . PHP_EOL;
                }
            }
        }

        if (!empty($destinationSubdirectory)) {
            $destinationSubdirectory .= DS . $interaction;
        } else {
            $destinationSubdirectory = $interaction;
        }
    }

    return $destinationSubdirectory;
}

/**
 * @param string $destinationSubdirectory
 * @param string $destinationDirectory
 *
 * @return string
 */
function checkForAlternativeFolderNames($destinationSubdirectory, $destinationDirectory)
{
    $destinationSubdirectoryTmp = explode(DS, $destinationSubdirectory);

    $alternativeFoldersMap = [
        'Explicit' => 'Adult',
        'Questionable' => 'Mature',
        'Safe' => 'Clean',
    ];

    if (isset($alternativeFoldersMap[$destinationSubdirectoryTmp[0]])) {
        if (is_dir($destinationDirectory . DS . $alternativeFoldersMap[$destinationSubdirectoryTmp[0]])) {
            $destinationSubdirectoryTmp[0] = $alternativeFoldersMap[$destinationSubdirectoryTmp[0]];
            $destinationSubdirectory = implode(DS, $destinationSubdirectoryTmp);
        } else {
            foreach (scandir($destinationDirectory) as $dir) {
                if ($dir === '..' || $dir === '.' || !is_dir($destinationDirectory . DS . $dir)) {
                    continue;
                }

                if (strpos($dir, $alternativeFoldersMap[$destinationSubdirectoryTmp[0]]) !== false) {
                    $destinationSubdirectoryTmp[0] = $dir;
                    $destinationSubdirectory = implode(DS, $destinationSubdirectoryTmp);
                    break;
                } elseif (strpos($dir, $destinationSubdirectoryTmp[0]) !== false) {
                    $destinationSubdirectoryTmp[0] = $dir;
                    $destinationSubdirectory = implode(DS, $destinationSubdirectoryTmp);
                    break;
                }
            }
        }
    }

    return $destinationSubdirectory;
}

/**
 * @param string $from
 * @param string $to
 *
 * @return bool
 */
function safeRename($from, $to)
{
    if (file_exists($to)) {
        echo 'File already exists: ' . $to . PHP_EOL;
        $to = SOURCE_PATH . DS . '! Exists' . DS . str_replace([SOURCE_PATH . DS, TARGET_PATH . DS], '', $to);

        return safeRename($from, $to);
    }

    $dirname = dirname($to);

    if (!is_dir($dirname) && !mkdir($dirname, 0755, true) && !is_dir($dirname)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $dirname));
    }

    echo 'Move: "' . str_replace(SOURCE_PATH . DS, '', $from) . '" => "' . str_replace([SOURCE_PATH . DS, TARGET_PATH . DS], '', $to) . '"' . PHP_EOL;

    return rename($from, $to);
}

/**
 * @param $file
 * @param $contents
 *
 * @return false|int
 */
function writeToTxt($file, $contents)
{
    if (!is_dir($concurrentDirectory = dirname($file)) && !mkdir($concurrentDirectory, 0755, true) && !is_dir($concurrentDirectory)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
    }

    return file_put_contents($file, $contents);
}

/**
 * Returns console waiting animation
 */
function waitingAnimation()
{
    global $waitLastTime, $waitAnimChar;

    $time = round(microtime(true), 1);

    if ($waitLastTime === $time) {
        return $waitAnimChar;
    }

    switch ($waitAnimChar) {
        case '|':
            $waitAnimChar = '/';
            break;
        case '/':
            $waitAnimChar = '-';
            break;
        case '-':
            $waitAnimChar = '\\';
            break;
        case '\\':
            $waitAnimChar = '|';
            break;
    }

    $waitLastTime = $time;

    return $waitAnimChar;
}

/**
 * @param int $count
 */
function showScanResult($count)
{
    global $buffer;

    $bufferLen = strlen($buffer) + 1;
    $scanResultStr = 'Found ' . $count . ' file(s).' . str_repeat(' ', $bufferLen - strlen($count));
    $scanResultLen = strlen($scanResultStr);

    $extraStr = '';
    if ($bufferLen > $scanResultLen) {
        $extraStr = str_repeat(' ', $bufferLen - $scanResultLen);
    }

    echo "\r" . $scanResultStr . $extraStr . PHP_EOL;
}

/** PROCEDURAL CODE */

$config = [
    'LOGIN'            => '',
    'API_KEY'          => '',
    'CONVERT'          => true,
    'REVERSE_SEARCH'   => null,
    'BY_RATING'        => false,
    'BY_INTERACTION'   => false,
    'REQUIRE_ALL_TAGS' => '',
    'REQUIRE_ONE_TAG'  => '',
];

// Load config
if (file_exists($cdir . DS . 'config.cfg')) {
    echo 'Loading config: ' . $cdir . DS . 'config.cfg' . PHP_EOL;

    $user_config = parse_ini_file($cdir . DS . 'config.cfg');
    $config = array_merge($config, $user_config);
}

for ($i = 1, $iMax = count($argv); $i < $iMax; $i++) {
    if (is_dir($argv[$i])) {
        if (isset($targetPath)) {
            if (isset($sourcePath)) {
                echo 'Target and source paths are already set!' . PHP_EOL;
                exit(1);
            }

            $sourcePath = $targetPath;
            $targetPath = $argv[$i];
        } else {
            $targetPath = $argv[$i];
        }
    }

    if (is_file($argv[$i])) {
        $file = explode('.', basename($argv[$i]));

        if ($file[1] !== 'cfg') {
            echo 'File is not a config file type (.cfg): ' . $argv[$i] . PHP_EOL;
            exit(1);
        }

        echo 'Loading user config: ' . realpath($argv[$i]) . PHP_EOL;
        $user_config = parse_ini_file($argv[$i]);
        /** @noinspection SlowArrayOperationsInLoopInspection */
        $config = array_merge($config, $user_config);
    }
}

// Prompt for path
if (!isset($targetPath)) {
    echo PHP_EOL;

    $cwd = getcwd();
    $targetPath = readline('Please enter target path: ');

    if (empty($targetPath)) {
        $targetPath = $cwd;
    }

    if (!is_dir(trim($targetPath))) {
        echo 'Invalid path!' . PHP_EOL;
        exit(1);
    }
}

if (!isset($sourcePath)) {
    $sourcePath = $targetPath;
}

echo 'Using target path: ' . realpath($targetPath) . PHP_EOL;

if ($targetPath !== $sourcePath) {
    echo 'Using source path: ' . realpath($sourcePath) . PHP_EOL;
}

// Ask if reverse search should be used
if ($config['REVERSE_SEARCH'] === null) {
    echo PHP_EOL;

    do {
        $useReverseSearch = readline('Use reverse search? [Y/n]: ');

        if (empty($useReverseSearch)) {
            $useReverseSearch = 'y';
        }
    } while (!in_array(strtolower($useReverseSearch), ['y', 'n']));

    if ($useReverseSearch === 'y') {
        $config['REVERSE_SEARCH'] = true;
    } else {
        $config['REVERSE_SEARCH'] = false;
    }
} else {
    $config['REVERSE_SEARCH'] = (bool)$config['REVERSE_SEARCH'];    // Make sure value is actually boolean
}

if (is_numeric($config['CONVERT'])) {
    $config['CONVERT'] = (int)$config['CONVERT'];
} else {
    $config['CONVERT'] = (bool)$config['CONVERT'];
}

// Define some stuff globally
define('SOURCE_PATH', realpath($sourcePath));
define('TARGET_PATH', realpath($targetPath));
define('CONVERT', $config['CONVERT']);
define('REQUIRE_ALL_TAGS', (string)$config['REQUIRE_ALL_TAGS']);
define('REQUIRE_ONE_TAG', (string)$config['REQUIRE_ONE_TAG']);
define('BY_RATING', (bool)$config['BY_RATING']);
define('BY_INTERACTION', (bool)$config['BY_INTERACTION']);

echo PHP_EOL;

// Scan the path
echo 'Scanning directory... ';

$files = [];
$i = 1;
foreach (new DirectoryIterator(SOURCE_PATH) as $file) {
    $buffer = "\r" . 'Scanning directory... ' . $i++ . ' files ';
    echo "\r" . $buffer . waitingAnimation();

    if ($file->isDot() || !$file->isFile() || strpos(mime_content_type($file->getPathname()), 'image') === false) {
        continue;
    }

    $files[] = $file->getFilename();
}

$totalFiles = count($files);
showScanResult($totalFiles);

// Initialize HTTP client
$options = [
    'base_uri' => 'https://e621.net',
    'headers'  => [
        'User-Agent' => 'e621 Categorizer - @jacklul on Telegram',
    ],
    'timeout'  => 60,
];

if (!empty($config['LOGIN']) && !empty($config['API_KEY'])) {
    $options['auth'] = [$config['LOGIN'], $config['API_KEY']];
}

$client = new Client($options);

// Make batch md5 search
if ($totalFiles > 1 && !isset($loadedPostsJson)) {
    echo 'Batch MD5 search...';

    $page = 1;
    while (true) {
        echo ' page ' . $page . '...';

        $new_files = array_slice($files, 100 * ($page - 1), 100);

        if (count($new_files) === 0) {
            break;
        }

        $page++;

        $md5s = [];
        foreach ($new_files as $file) {
            $file_path = SOURCE_PATH . DS . $file;

            if (!is_file($file_path)) {
                continue;
            }

            $md5_file = md5_file($file_path);
            $md5Cache[$md5_file] = [];
            $md5s[] = $md5_file;
        }

        batchMd5($md5s);
    }

    echo PHP_EOL;
}

echo PHP_EOL;

// Do the actual thing
$i = 0;

foreach ($files as $file) {
    $i++;

    $destinationSubdirectory = '';
    $file_path = SOURCE_PATH . DS . $file;
    $debugData = '';

    if (!is_file($file_path)) {
        continue;
    }

    echo '[' . $i . '/' . $totalFiles . '] File: ' . basename($file_path) . PHP_EOL;

    $file_md5 = md5_file($file_path);
    $post_id = getPostIdByMd5($file_md5);

    if ($post_id) {
        echo 'Found using MD5 search' . PHP_EOL;

        $destinationSubdirectory = categorize($file_path, $post_id);
    } else {
        echo 'Post by MD5 not found: ' . $file_md5 . PHP_EOL;

        if ($config['REVERSE_SEARCH'] === true) {
            $buffer = 'Trying reverse search...';

            $reverse_search = reverseSearch($file_path);
            $fileContentsCache = null;

            echo PHP_EOL;

            if (is_array($reverse_search)) {
                if (!empty($reverse_search)) {
                    $reverse_search = array_unique($reverse_search);

                    if (count($reverse_search) === 1) {
                        $destinationSubdirectory = categorize($file_path, $reverse_search[0]);
                    } else {
                        $destinationSubdirectory = '! Multiple matches';

                        echo 'Multiple posts matched!' . PHP_EOL;
                        
                        $debugData = 'Multiple posts matched: ' . PHP_EOL;
                        foreach ($reverse_search as $result) {
                            $debugData .= 'https://e621.net/posts/' . $result . PHP_EOL;
                        }
                    }
                }
            } else {
                $destinationSubdirectory = '! Error';
            }
        }

        if (empty($destinationSubdirectory) && file_exists($file_path)) {
            $destinationSubdirectory = '! Not found';
        }
    }

    if (substr($destinationSubdirectory, 0, 1) === '!') {
        $destinationDirectory = SOURCE_PATH;
    } else {
        $destinationDirectory = TARGET_PATH;
    }

    $destinationSubdirectory = checkForAlternativeFolderNames($destinationSubdirectory, $destinationDirectory);

    safeRename($file_path, $destinationDirectory . DS . $destinationSubdirectory . DS . $file);

    if (!empty($debugData)) {
        writeToTxt($destinationDirectory . DS . $destinationSubdirectory . DS . basename($file) . '.txt', $debugData . PHP_EOL);
    }

    echo PHP_EOL;
}
