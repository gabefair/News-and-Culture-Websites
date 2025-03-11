<?php
// Move these to environment variables
$dbHost = getenv('DB_HOST') ?: 'example.com';
$dbName = getenv('DB_NAME') ?: 'Database_name';
$dbUser = getenv('DB_USER') ?: 'Database_user';
$dbPassword = getenv('DB_PASSWORD') ?: 'Database_password';
$dbPort = '3307';
$dbCharset = 'utf8mb4';
$slow_mode = 0;

function main(array $event, object $context) {
    if ($GLOBALS['slow_mode'] && rand(0,10) < 5) {
        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'message' => "Slow mode activated. The downstream archiving service is reaching current bandwidth capacity and is requesting less traffic for the moment. Please try again shortly"
            ])
        ];
    }

    try {
        $pdo = connectToDB();
        $result = getNextUrlNEW($pdo, $nsfw = 0, $urgent = 0);
        return redirectToNextUrl($pdo, $result, $event);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        if (strpos($errorMessage, '@') !== false) {
            return substr($errorMessage, strpos($errorMessage, '@'));
        }
        return [
            'statusCode' => 500,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'error' => 'Internal server error: ' . $errorMessage
            ])
        ];
    }
}

function connectToDB() {
    global $dbHost, $dbName, $dbUser, $dbPassword, $dbCharset, $dbPort;
    try {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ];
        
        return new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset;port=$dbPort", 
            $dbUser, 
            $dbPassword, 
            $options
        );
    } catch (PDOException $e) {
        $errorCode = $e->getCode();
        $errorMessage = match($errorCode) {
            2002 => 'Database connection timeout',
            1045 => 'Invalid database credentials',
            default => 'Database connection error'
        };
        throw new Exception($errorMessage . ': ' . $e->getMessage());
    }
}

function redirectToNextUrl($pdo, $result, array $event) {
    if (is_string($result)) {
        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['message' => $result])
        ];
    }

    if (!$result) {
        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['message' => 'All archivers are busy. Try again soon. (1)'])
        ];
    }

    try {

        if ($result['result_url'] == "AliasNotFound") {
            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['message' => 'Alias not found'])
            ];
        }

        if (!isset($result['result_url'])) {
            return [
                'statusCode' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['message' => '4ab6 TypeError'])
            ];
        }

        // Update database records
        if ($result['one_off'] == 1) {
            $updateStmt = $pdo->prepare("UPDATE oneoffurls SET archived = 1 WHERE one_off_id = ?");
            $updateStmt->execute([$result['one_off_id']]);
        } else {
            if (isset($result['has_alias']) && $result['has_alias'] == 1) {
                $updateStmt = $pdo->prepare("UPDATE aliases SET date_last_archived = NOW(), redirect_proxy = NOT redirect_proxy WHERE alias_id = ?");
                $updateStmt->execute([$result['alias_id']]);
            }

            // Create action record if it doesn't exist
            if (!isset($result['archived_count']) || $result['archived_count'] === null) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO actions (id, archived_count, date_last_archived, redirect_proxy) 
                    VALUES (?, 0, NOW(), 0)
                    ON DUPLICATE KEY UPDATE id = id"  // No change on duplicate to avoid resetting counts
                );
                $insertStmt->execute([$result['id']]);
            }
            else {
                $updateStmt = $pdo->prepare("UPDATE actions SET archived_count = archived_count + 1, redirect_proxy = NOT redirect_proxy WHERE id = ?");
                $updateStmt->execute([$result['id']]);
            }
        }

        // Log to history
        $insertStmt = $pdo->prepare("INSERT INTO history (request_ip, url, url_id, alias_id, one_off, redirect_proxy, priority_score) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $values = [getClientIP($event), $result['result_url'], $result['id'] ?? null, $result['alias_id'] ?? null, $result['one_off'], $result['redirect_proxy'] ?? 0, $result['priority_score'] ?? null];
        $insertStmt->execute($values);

        // Return redirect response
        return redirectToArchive($result, $result['redirect_proxy'] ?? 0);

    } catch (PDOException $e) {
        if (isset($updateStmt)) {
            $updateStmt->closeCursor();
        }
        throw new Exception('Gabe Database error: ' . $e->getMessage() . ' \n'. implode(", ", $result)); //or $string = "The array is: " . json_encode($array);
    }
}

function redirectToArchive($result, $useProxy = 0) {
    $proxyUrlOne = "https://example.com/proxy1";
    $proxyUrlTwo = "https://example.com/proxy2";
    $url = $result['result_url'];

    if (!$result['result_url']) {
        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['message' => 'No URL found to archive.'])
        ];
    }

    $proxyUrl = date("s") % 2 == 0 ? $proxyUrlOne : $proxyUrlTwo;

    $encodedUrl = urlencode($url);
    
    if ($result['has_alias'] == 1 || date("s") % 2 == 0 || strpos($url, ".htm") == true || strpos($url, ".asp") == true || strpos($url, ".txt") == true || strpos($url, ".shtm") == true || strpos($url, ".php") == true || strpos($url, "?") == true || strpos($url, "=") == true || strpos($url, "%") == true || strpos($url, ".pdf") == true) {
        $archiveUrl = $useProxy ? 
            "https://archive.today/?run=1&url=" . urlencode($proxyUrl .$encodedUrl) : 
            "https://archive.today/?run=1&url=$encodedUrl";
    } else {
        $archiveUrl = $useProxy ? 
            "https://archive.ph/?run=1&url=" . urlencode($proxyUrl .$encodedUrl . "/") : 
            "https://archive.ph/?run=1&url=$encodedUrl/";
    }

    return [
        'statusCode' => 307,
        'headers' => [
            'Location' => $archiveUrl,
            'Content-Type' => 'application/json',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => 'Sat, 26 Jul 1997 05:00:00 GMT'
        ],
        'body' => ''
    ];
}

function getNextUrlNEW(PDO $pdo, int $nsfw = 0, int $urgent = 0): ?array {
    // $dayOfWeek = date('w');
    // $isWeekend = in_array($dayOfWeek, [0, 6]);

    $currentHour = (int)date('G'); // Get current hour in 24-hour format (0-23)
    $dayOfWeek = (int)date('w');   // Get day of week (0-6)
    
    $isWeekend = in_array($dayOfWeek, [0, 6]) || $currentHour < 6;

    $priorities = [
        ['prioritized' => 1, 'deprioritized' => 0, 'bumpUp' => 0],   // Prioritized
        ['prioritized' => 0, 'deprioritized' => 0, 'bumpUp' => 0],   // Normal
        ['one_off' => true],                                         // One-off URLs
        ['prioritized' => 0, 'deprioritized' => 1, 'bumpUp' => 0.5], // Deprioritized (lowest priority)
    ];

    if (date("s") == 59) {
        shuffle($priorities); // To give the maybe long ignored urls a chance
    }

    foreach ($priorities as $priority) {
        $statement = null;
        $result = null;
        if (isset($priority['one_off'])) {
            $statement = "SELECT * FROM oneoffurls WHERE archived IS NULL OR archived != 1 LIMIT 1";
        } else {
            $statement = buildStatement($pdo, $nsfw, $priority['prioritized'], $urgent, $priority['deprioritized'], $isWeekend, $priority['bumpUp']);
        }

        try {
            $stmt = $pdo->prepare($statement);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if ($result) {
                $result['result_url'] = $result['url'] ?? null;
                $result['alias_id'] = $result['alias_id'] ?? null;

                if ($result['one_off_id']){
                    $result['one_off'] = 1;
                }else{
                    $result['one_off'] = 0;
                }

                if (isset($result['has_alias']) && $result['has_alias'] == 1) {
                    $aliasStmt = $pdo->prepare("SELECT * FROM aliases al WHERE url_id = :url_id AND deleted != 1 ORDER BY al.date_last_archived LIMIT 1");
                    $aliasStmt->execute(['url_id' => $result['id']]);
                    $alias = $aliasStmt->fetch(PDO::FETCH_ASSOC);

                    if ($alias) {
                        $result['result2'] = $alias;
                        $result['aliased_url'] = $result['result_url'];
                        $result['result_url'] = $alias['alias'];
                        $result['alias_id'] = $alias['alias_id'];
                        $result['redirect_proxy'] = $alias['redirect_proxy'];
                    } else {
                        $result['result_url'] = "AliasNotFound";
                        $result['alias_id'] = "0";
                    }
                }

                if (isset($result['parameterized']) && $result['parameterized'] == 1) {
                    $result['redirect_proxy']= 0; //Never use proxy for these parameterized urls as they are unique each time
                    $replacements = [
                        '<<d-m-Y>>' => date("d-m-Y"),
                        '<<Y/m/d>>' => date("Y/m/d"),
                    ];

                    foreach ($replacements as $placeholder => $replacement) {
                        $result['result_url'] = str_replace($placeholder, $replacement, $result['result_url']);
                    }

                    if (isset($result['aliased_url']) && strpos($result['aliased_url'], '<<en_country>>') !== false && isset($alias)) {
                        $result['result_url'] = str_replace("<<en_country>>", $alias['alias'], $result['aliased_url']);
                    }
                }
                if ($result['noproxy'] == 1){
                    $result['redirect_proxy']= 0;
                }
                return $result; // Return immediately when a result is found
            }
        } catch (PDOException $e) {
            if ($stmt) {
                $stmt->closeCursor();  // Close cursor even on error
            }
            if ($aliasStmt) {
                $aliasStmt->closeCursor();  // Close cursor even on error
            }
            error_log($e->getMessage());
            // Consider throwing the exception instead of just logging it.
            return null; // Return null on error
        }
    }

    echo "All archivers are busy. Try again soon. (1)"; // Only reached if no URL is found
    return null; // Return null if no URL is found after all attempts
}

function buildStatement($pdo, $nsfw, $prioritized, $urgent, $deprioritized, $weekend, $bumpUp=0) {
    $statement = "SELECT *, 
    CASE
        WHEN a.date_last_archived IS NULL THEN '2000-01-01 00:00:01'
        ELSE (((UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(a.date_last_archived)) / 3600) / u.freq) 
    END AS priority_score 
    FROM urls u 
    LEFT JOIN actions a ON u.id = a.id 
    WHERE u.deleted != 1 
    AND (
    a.date_last_archived IS NULL
        OR ((UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(a.date_last_archived)) / 3600) > (u.freq - $bumpUp)
    )";

    if ($weekend == 1) {
        $statement .= " AND (url NOT LIKE '%_-_-_%' AND url NOT LIKE '%_/_/_%')";
    }

    if ($nsfw == 0) {
        $statement .= " AND u.nsfw != 1";
    }

    if ($deprioritized == 0) { //the default state is ==0 where anything in the deprioritized column should be equal to 0, and only selected when this !=1 condition is removed
        $statement .= " AND u.deprioritized != 1";
    }

    if ($prioritized == 1) {
        $statement .= " AND u.priority = 1";
    }

    if ($urgent == 1) {
        $statement .= " AND u.freq < 13";
    }

    $statement .= " ORDER BY (((UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(a.date_last_archived)) / 3600) / u.freq) DESC LIMIT 1 ";

    return $statement;
}

function getClientIP(array $event) {
    
    // Check if we have headers in the event object
    if (isset($event['http']['headers']['x-forwarded-for'])) {
        $ips = explode(',', $event['http']['headers']['x-forwarded-for']);
        return trim($ips[0]);
    }
    
    // Fallback to other possible header locations in the event object
    $possibleHeaders = [
        'x-real-ip',
        'client-ip'
    ];
    
    foreach ($possibleHeaders as $header) {
        if (isset($event['http']['headers'][$header])) {
            return $event['http']['headers'][$header];
        }
    }
    
    return '0.0.0.0'; // Default fallback
}
