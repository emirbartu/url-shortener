<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

// Database connection
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'url_shortener';
$username = getenv('DB_USERNAME') ?: 'url_user';
$password = getenv('DB_PASSWORD') ?: 'password';

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
} catch(PDOException $e) {
    die("Could not connect to the database $dbname: " . $e->getMessage());
}

// Function to generate QR code
function generateQRCode($url) {
    require_once 'vendor/autoload.php';

    $tempDir = 'qr_codes/';
    if (!file_exists($tempDir)) {
        mkdir($tempDir);
    }

    // Ensure proper UTF-8 encoding
    $encodedUrl = mb_convert_encoding($url, 'UTF-8', 'UTF-8');
    $fileName = $tempDir . md5($encodedUrl) . '.png';

    $result = Builder::create()
        ->writer(new PngWriter())
        ->writerOptions([])
        ->data($encodedUrl)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(ErrorCorrectionLevel::Low)
        ->size(300)
        ->margin(10)
        ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
        ->validateResult(false)
        ->build();

    $result->saveToFile($fileName);

    return $fileName;
}

// Function to generate short code
function generateShortCode() {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789üÜäÄöÖß';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[rand(0, strlen($chars) - 1)];
    }
    $code = str_replace(['o', 'O', '0', 'i', 'I', '1', 'l', 'L'], '', $code);
    return strtolower($code);
}

// Function to handle URL shortening
function shortenURL($originalURL, $customCode = null, $expirationOption = '1m') {
    global $pdo;

    $maxAttempts = 5;
    $attempts = 0;

    do {
        if ($customCode) {
            $shortCode = mb_strtolower($customCode, 'UTF-8');
        } else {
            $shortCode = generateShortCode();
        }
        $qrCodePath = generateQRCode($shortCode);

        // Calculate expiration date
        $expirationDate = null;
        switch ($expirationOption) {
            case '1h': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 hour')); break;
            case '1d': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 day')); break;
            case '1w': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 week')); break;
            case '1m': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 month')); break;
            case '1y': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 year')); break;
            case 'lifetime': $expirationDate = null; break;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO shortened_urls (original_url, short_code, custom_short_code, expiration_date, qr_code_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$originalURL, $shortCode, $customCode, $expirationDate, $qrCodePath]);
            $shortUrl = "http://yourdomain.com/$shortCode";
            return ['success' => true, 'short_url' => $shortUrl, 'short_code' => $shortCode];
        } catch (PDOException $e) {
            if ($e->getCode() == '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $attempts++;
                if ($customCode) {
                    return ['success' => false, 'error' => "Custom short code already exists. Please choose a different one."];
                }
                // If it's not a custom code, we'll try again with a new generated code
            } else {
                return ['success' => false, 'error' => "An unexpected error occurred. Please try again."];
            }
        }
    } while ($attempts < $maxAttempts);

    return ['success' => false, 'error' => "Failed to generate a unique short code after $maxAttempts attempts. Please try again."];
}

// Function to handle link list creation
function createLinkList($links, $expirationOption = '1m') {
    global $pdo;

    $shortCode = generateShortCode();
    $qrCodePath = generateQRCode($shortCode);

    // Calculate expiration date
    $expirationDate = null;
    switch ($expirationOption) {
        case '1h': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 hour')); break;
        case '1d': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 day')); break;
        case '1w': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 week')); break;
        case '1m': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 month')); break;
        case '1y': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 year')); break;
        case 'lifetime': $expirationDate = null; break;
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("INSERT INTO link_lists (short_code, expiration_date, qr_code_path) VALUES (?, ?, ?)");
        $stmt->execute([$shortCode, $expirationDate, $qrCodePath]);
        $listId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO list_items (list_id, url, title, description) VALUES (?, ?, ?, ?)");
        foreach ($links as $link) {
            $stmt->execute([$listId, $link['url'], $link['title'], $link['description']]);
        }

        $pdo->commit();
        return $shortCode;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Function to handle clipboard entry creation
function createClipboardEntry($content, $expirationOption = '1m') {
    global $pdo;

    $shortCode = generateShortCode();
    $qrCodePath = generateQRCode($shortCode);

    // Calculate expiration date
    $expirationDate = null;
    switch ($expirationOption) {
        case '1h': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 hour')); break;
        case '1d': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 day')); break;
        case '1w': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 week')); break;
        case '1m': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 month')); break;
        case '1y': $expirationDate = date('Y-m-d H:i:s', strtotime('+1 year')); break;
        case 'lifetime': $expirationDate = null; break;
    }

    $stmt = $pdo->prepare("INSERT INTO clipboard_entries (content, short_code, expiration_date, qr_code_path) VALUES (?, ?, ?, ?)");
    $stmt->execute([$content, $shortCode, $expirationDate, $qrCodePath]);

    return $shortCode;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'shorten_url':
                $originalURL = $_POST['url'];
                $customCode = !empty($_POST['custom_code']) ? $_POST['custom_code'] : null;
                $expirationOption = $_POST['expiration'];
                $result = shortenURL($originalURL, $customCode, $expirationOption);
                if ($result['success']) {
                    echo json_encode(['success' => true, 'short_url' => $result['short_url']]);
                } else {
                    echo json_encode(['success' => false, 'error' => $result['error']]);
                }
                exit;

            case 'create_link_list':
                $links = json_decode($_POST['links'], true);
                $expirationOption = $_POST['expiration'];
                $shortCode = createLinkList($links, $expirationOption);
                // Return JSON response with short URL for the list
                echo json_encode(['short_url' => "http://yourdomain.com/list/$shortCode"]);
                exit;

            case 'create_clipboard':
                $content = $_POST['content'];
                $expirationOption = $_POST['expiration'];
                $shortCode = createClipboardEntry($content, $expirationOption);
                // Return JSON response with short URL for the clipboard entry
                echo json_encode(['short_url' => "http://yourdomain.com/clip/$shortCode"]);
                exit;
        }
    }
}

// Handle redirects
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$segments = explode('/', trim($path, '/'));

if (!empty($segments[0])) {
    $shortCode = $segments[0];

    // Check if it's a URL redirect
    $stmt = $pdo->prepare("SELECT original_url, qr_code_path FROM shortened_urls WHERE LOWER(short_code) = LOWER(?) AND (expiration_date IS NULL OR expiration_date > NOW())");
    $stmt->execute([strtolower($shortCode)]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        // Display redirect page with QR code
        echo "<!DOCTYPE html>
              <html lang='en'>
              <head>
                  <meta charset='UTF-8'>
                  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                  <title>Redirecting...</title>
                  <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
              </head>
              <body class='bg-light'>
                  <div class='container mt-5'>
                      <div class='alert alert-info' role='alert'>
                          You will be redirected in 3 seconds...
                      </div>
                      <div class='text-center mt-3'>
                          <img src='" . htmlspecialchars($result['qr_code_path']) . "' alt='QR Code' class='img-fluid'>
                      </div>
                  </div>
                  <script>
                      setTimeout(function() {
                          window.location.href = '" . htmlspecialchars($result['original_url']) . "';
                      }, 3000);
                  </script>
              </body>
              </html>";
        exit;
    }

    // Check if it's a link list
    if ($segments[0] === 'list' && !empty($segments[1])) {
        $listCode = $segments[1];
        $stmt = $pdo->prepare("SELECT * FROM link_lists WHERE short_code = ? AND (expiration_date IS NULL OR expiration_date > NOW())");
        $stmt->execute([$listCode]);
        $list = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($list) {
            $stmt = $pdo->prepare("SELECT * FROM list_items WHERE list_id = ?");
            $stmt->execute([$list['id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo "<!DOCTYPE html>
                  <html lang='en'>
                  <head>
                      <meta charset='UTF-8'>
                      <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                      <title>Link List</title>
                      <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
                  </head>
                  <body class='bg-light'>
                      <div class='container mt-5'>
                          <h1 class='mb-4'>Link List</h1>
                          <ul class='list-group'>";
            foreach ($items as $item) {
                echo "<li class='list-group-item'>
                          <h5><a href='" . htmlspecialchars($item['url']) . "' target='_blank'>" . htmlspecialchars($item['title']) . "</a></h5>
                          <p>" . htmlspecialchars($item['description']) . "</p>
                      </li>";
            }
            echo "</ul>
                      </div>
                  </body>
                  </html>";
        } else {
            echo "Link list not found or expired.";
        }
        exit;
    }

    // Check if it's a clipboard entry
    if ($segments[0] === 'clip' && !empty($segments[1])) {
        $clipCode = $segments[1];
        $stmt = $pdo->prepare("SELECT * FROM clipboard_entries WHERE short_code = ? AND (expiration_date IS NULL OR expiration_date > NOW())");
        $stmt->execute([$clipCode]);
        $clip = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($clip) {
            echo "<!DOCTYPE html>
                  <html lang='en'>
                  <head>
                      <meta charset='UTF-8'>
                      <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                      <title>Clipboard Entry</title>
                      <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css' rel='stylesheet'>
                  </head>
                  <body class='bg-light'>
                      <div class='container mt-5'>
                          <h1 class='mb-4'>Clipboard Entry</h1>
                          <div class='card'>
                              <div class='card-body'>
                                  <pre>" . htmlspecialchars($clip['content']) . "</pre>
                              </div>
                          </div>
                      </div>
                  </body>
                  </html>";
        } else {
            echo "Clipboard entry not found or expired.";
        }
        exit;
    }
}

// If no redirect or special page, show the main page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>URL Shortener</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: white; color: black; }
        .service-icon { font-size: 2rem; margin-bottom: 1rem; }
        .card { transition: transform 0.3s; }
        .card:hover { transform: translateY(-5px); }
        .short-url { text-transform: lowercase; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-5">URL Shortener</h1>

        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">URL Shortener</h5>
                        <form id="url-shortener-form">
                            <input type="hidden" name="action" value="shorten_url">
                            <div class="mb-3">
                                <input type="url" class="form-control" name="url" placeholder="Enter URL" required>
                            </div>
                            <div class="mb-3">
                                <input type="text" class="form-control" name="custom_code" placeholder="Custom short code (optional)">
                            </div>
                            <div class="mb-3">
                                <select class="form-select" name="expiration">
                                    <option value="1h">1 hour</option>
                                    <option value="1d">1 day</option>
                                    <option value="1w">1 week</option>
                                    <option value="1m" selected>1 month</option>
                                    <option value="1y">1 year</option>
                                    <option value="lifetime">Lifetime</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Shorten URL</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Link List</h5>
                        <form id="link-list-form">
                            <input type="hidden" name="action" value="create_link_list">
                            <div id="link-list-inputs">
                                <div class="mb-3">
                                    <input type="url" class="form-control" name="url[]" placeholder="Enter URL" required>
                                    <input type="text" class="form-control mt-2" name="title[]" placeholder="Title">
                                    <textarea class="form-control mt-2" name="description[]" placeholder="Description"></textarea>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mb-3" id="add-link">Add Another Link</button>
                            <div class="mb-3">
                                <select class="form-select" name="expiration">
                                    <option value="1h">1 hour</option>
                                    <option value="1d">1 day</option>
                                    <option value="1w">1 week</option>
                                    <option value="1m" selected>1 month</option>
                                    <option value="1y">1 year</option>
                                    <option value="lifetime">Lifetime</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Create Link List</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Online Clipboard</h5>
                        <form id="clipboard-form">
                            <input type="hidden" name="action" value="create_clipboard">
                            <div class="mb-3">
                                <textarea class="form-control" name="content" rows="5" placeholder="Enter your text here" required></textarea>
                            </div>
                            <div class="mb-3">
                                <select class="form-select" name="expiration">
                                    <option value="1h">1 hour</option>
                                    <option value="1d">1 day</option>
                                    <option value="1w">1 week</option>
                                    <option value="1m" selected>1 month</option>
                                    <option value="1y">1 year</option>
                                    <option value="lifetime">Lifetime</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Create Clipboard Entry</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.8/clipboard.min.js"></script>
    <script>
        $(document).ready(function() {
            // URL Shortener form submission
            $('#url-shortener-form').submit(function(e) {
                e.preventDefault();
                $.post('', $(this).serialize(), function(data) {
                    if (data.error) {
                        $('#url-shortener-error').text(data.error).removeClass('d-none');
                    } else {
                        var shortUrl = data.short_url;
                        $('#url-shortener-result').html('Short URL: <a href="' + shortUrl + '" target="_blank">' + shortUrl + '</a>').removeClass('d-none');
                        // Create a temporary input to copy the short URL
                        var $temp = $("<input>");
                        $("body").append($temp);
                        $temp.val(shortUrl).select();
                        document.execCommand("copy");
                        $temp.remove();
                        $('#url-shortener-copy-message').text('Short URL copied to clipboard!').removeClass('d-none');
                        setTimeout(function() {
                            $('#url-shortener-copy-message').addClass('d-none');
                        }, 3000);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                    $('#url-shortener-error').text('An error occurred: ' + textStatus).removeClass('d-none');
                });
            });

            // Link List form submission
            $('#link-list-form').submit(function(e) {
                e.preventDefault();
                var links = [];
                $('#link-list-inputs > div').each(function() {
                    links.push({
                        url: $(this).find('input[name="url[]"]').val(),
                        title: $(this).find('input[name="title[]"]').val(),
                        description: $(this).find('textarea[name="description[]"]').val()
                    });
                });
                $.post('', {
                    action: 'create_link_list',
                    links: JSON.stringify(links),
                    expiration: $('select[name="expiration"]').val()
                }, function(data) {
                    alert('Link List URL: ' + data.short_url);
                }, 'json');
            });

            // Add another link input
            $('#add-link').click(function() {
                $('#link-list-inputs').append(`
                    <div class="mb-3">
                        <input type="url" class="form-control" name="url[]" placeholder="Enter URL" required>
                        <input type="text" class="form-control mt-2" name="title[]" placeholder="Title">
                        <textarea class="form-control mt-2" name="description[]" placeholder="Description"></textarea>
                    </div>
                `);
            });

            // Clipboard form submission
            $('#clipboard-form').submit(function(e) {
                e.preventDefault();
                $.post('', $(this).serialize(), function(data) {
                    alert('Clipboard Entry URL: ' + data.short_url);
                }, 'json');
            });
        });
    </script>
</body>
</html>
