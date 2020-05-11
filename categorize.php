<?php

define('DS', DIRECTORY_SEPARATOR);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require __DIR__ . DS . 'vendor' . DS . 'autoload.php';

$postsCache = [];
$md5Cache = [];
$client = null;
$lastRequest = 0;
$buffer = '';
$waitLastTime = 0;
$waitAnimChar = '|';

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
 * @return string|bool|null
 */
function getPostRating($post_id)
{
    $result = getPosts(['tags' => 'id:' . $post_id . ' status:any']);

    if (is_array($result)) {
        if (isset($result['posts'][0]['rating'])) {
            return $result['posts'][0]['rating'];
        }

        echo 'Unable to fetch post rating: Post not found' . PHP_EOL;

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

        echo 'Unable to fetch post tags: Post not found' . PHP_EOL;

        return false;
    }

    echo 'API failure: ' . ($result['error'] ?: 'Unknown error') . PHP_EOL;

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
        $mime_type = mime_content_type($file);

        if ($mime_type === 'image/png') {
            $image = imagecreatefrompng($file);
        } elseif ($mime_type === 'image/jpeg') {
            $image = imagecreatefromjpeg($file);
        } elseif ($mime_type === 'image/gif') {
            $image = imagecreatefromgif($file);
        }

        if (isset($image)) {
            ob_start();
            imagejpeg($image, null, 90);
            $contents = ob_get_clean();

            imagedestroy($image);
        } else {
            echo "\r" . $buffer . ' file conversion failed';

            return false;
        }

        $request_options = [
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => $contents,
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
                    print(str_repeat(' ', 10) . "\r" . $buffer . ' ' . round(($progress * 100) / $total, 0)) . "% ";
                }
            },
        ];

        if ($lastRequest === time()) {
            sleep(1);
        }

        $response = $client->request('GET', 'iqdb_queries.json', $request_options);
        $lastRequest = time();

        $raw_response = (string)$response->getBody();
        $json_result = json_decode($raw_response, true);

        if (is_array($json_result)) {
            if (count($json_result) > 0 && isset($json_result[0]['post_id'])) {
                $results = [];
                foreach ($json_result as $result) {
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
 * @return string
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

    foreach ($genderTags as $genderTag => $genderName) {
        if (is_numeric($genderTag)) {
            $genderTag = $genderName;
        }

        if (in_array($genderTag, $tags, true)) {
            return ucfirst($genderName);
        }
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
    $destinationDirectory = '';

    if (!empty(REQUIRE_ALL_TAGS) || !empty(REQUIRE_ONE_TAG)) {
        $destinationDirectoryForMissingTags = '! No match';
        $tags = getPostTags($post_id);

        if (is_array($tags)) {
            $tagsAsOneArray = getTagsAsOneArray($tags);

            if (!empty(REQUIRE_ALL_TAGS)) {
                $requiredTags = explode(' ', REQUIRE_ALL_TAGS);

                foreach ($requiredTags as $requiredTag) {
                    if (!in_array($requiredTag, $tagsAsOneArray, true)) {

                        echo 'Missing all required tag(s)!' . PHP_EOL;

                        return $destinationDirectoryForMissingTags;
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

                    return $destinationDirectoryForMissingTags;
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
            $destinationDirectory = $ratings[$rating];

            echo 'Rating: ' . $destinationDirectory . PHP_EOL;
        } elseif (!empty($rating)) {
            echo 'Unknown rating value: ' . $rating . PHP_EOL;

            return '! Error';
        } else {
            echo 'Failed to categorize by rating' . PHP_EOL;

            return '! Unknown';
        }
    }

    if (BY_INTERACTION === true) {
        $interaction = '! Unknown';

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

                if (empty($gender)) {
                    $interaction .= DS . '! No gender';

                    echo 'Missing gender tag!' . PHP_EOL;
                } else {
                    $interaction .= DS . $gender;

                    echo 'Gender: ' . $gender . PHP_EOL;
                }
            } else {
                $interactionTag = checkForInteractionTag($tagsAsOneArray);

                if (!is_array($interactionTag) && !empty($interactionTag)) {
                    $interaction = $interactionTag;

                    echo 'Interaction: ' . $interaction . PHP_EOL;
                } elseif (is_array($interactionTag)) {
                    $interaction = '! Conflict';

                    echo 'Conflict: ' . implode(', ', $interactionTag) . PHP_EOL;

                    $debugData = 'Multiple interaction tags matched: ' . PHP_EOL . implode(PHP_EOL, $interactionTag);
                    $debugData .= PHP_EOL . PHP_EOL . 'Tags: ' . print_r($tagsAsOneArray, true);
                }
            }
        }

        if (!empty($destinationDirectory)) {
            $destinationDirectory .= DS . $interaction;
        } else {
            $destinationDirectory = $interaction;
        }
    }

    if (isset($debugData) && !empty($debugData)) {
        writeToTxt(PATH . DS . $destinationDirectory . DS . basename($file) . '.txt', $debugData . PHP_EOL);
    }

    return $destinationDirectory;
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

        return safeRename($from, PATH . DS . 'Exists' . DS . basename($from));
    }

    $dirname = dirname($to);

    if (!is_dir($dirname) && !mkdir($concurrentDirectory = $dirname, 0755, true) && !is_dir($dirname)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $dirname));
    }

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
 * Prints console waiting animation
 */
function waitingAnimation()
{
    global $buffer, $waitLastTime, $waitAnimChar;

    $time = round(microtime(true), 1);

    if ($waitLastTime === $time) {
        return;
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

    echo "\r" . $buffer . $waitAnimChar;
}

/**
 * @param int $count
 */
function showScanResult($count)
{
    global $buffer;

    $bufferLen = strlen($buffer) + 1;
    $scanResultStr = 'Found ' . $count . ' file(s).';
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
    'REVERSE_SEARCH'   => null,
    'BY_RATING'        => false,
    'BY_INTERACTION'   => false,
    'REQUIRE_ALL_TAGS' => '',
    'REQUIRE_ONE_TAG'  => '',
];

// Load config
if (file_exists(__DIR__ . DS . 'config.cfg')) {
    echo 'Loading config: ' . __DIR__ . DS . 'config.cfg' . PHP_EOL;

    $user_config = parse_ini_file(__DIR__ . DS . 'config.cfg');
    $config = array_merge($config, $user_config);
}

for ($i = 1, $iMax = count($argv); $i < $iMax; $i++) {
    if (is_dir($argv[$i])) {
        if (isset($targetPath)) {
            echo 'Target path is already set!' . PHP_EOL;
            exit(1);
        }

        $targetPath = $argv[$i];
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

echo 'Using path: ' . realpath($targetPath) . PHP_EOL;

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

// Define some stuff globally
define('PATH', $targetPath);
define('REQUIRE_ALL_TAGS', (string)$config['REQUIRE_ALL_TAGS']);
define('REQUIRE_ONE_TAG', (string)$config['REQUIRE_ONE_TAG']);
define('BY_RATING', (bool)$config['BY_RATING']);
define('BY_INTERACTION', (bool)$config['BY_INTERACTION']);

echo PHP_EOL;

// Scan the path
echo $buffer = 'Scanning directory... ';

$files = [];
foreach (new DirectoryIterator($targetPath) as $file) {
    waitingAnimation();

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
if ($totalFiles > 1) {
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
            $file_path = PATH . DS . $file;

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

    $destinationDirectory = '';
    $file_path = PATH . DS . $file;

    if (!is_file($file_path)) {
        continue;
    }

    echo '[' . $i . '/' . $totalFiles . '] File: ' . basename($file_path) . PHP_EOL;

    $file_md5 = md5_file($file_path);
    $post_id = getPostIdByMd5($file_md5);

    if ($post_id) {
        echo 'Found using MD5 search' . PHP_EOL;
        $destinationDirectory = categorize($file_path, $post_id);
    } else {
        echo 'Post by MD5 not found: ' . $file_md5 . PHP_EOL;

        if ($config['REVERSE_SEARCH'] === true) {
            $buffer = 'Trying reverse search...';
            $reverse_search = reverseSearch($file_path);

            echo PHP_EOL;

            if (is_array($reverse_search)) {
                if (!empty($reverse_search)) {
                    $reverse_search = array_unique($reverse_search);

                    if (count($reverse_search) === 1) {
                        $destinationDirectory = categorize($file_path, $reverse_search[0]);
                    } else {
                        $destinationDirectory = '! To check';

                        echo 'Multiple posts matched!' . PHP_EOL;

                        $debugData = '';
                        foreach ($reverse_search as $result) {
                            $debugData .= 'https://e621.net/posts/' . $result . PHP_EOL;
                        }

                        writeToTxt(PATH . DS . $destinationDirectory . DS . $file . '.txt', trim($debugData) . PHP_EOL);
                    }
                }
            } else {
                $destinationDirectory = '! Error';
            }
        }

        if (empty($destinationDirectory) && file_exists($file_path)) {
            $destinationDirectory = '! Unknown';
        }
    }

    safeRename($file_path, PATH . DS . $destinationDirectory . DS . $file);

    echo PHP_EOL;
}
