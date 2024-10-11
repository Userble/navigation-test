<?php
// Start the session
session_start();

// Security headers
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: no-referrer");

// IMPORTANT SETUP STEP - Enter YOUR database credentials here
$host = 'localhost';
$dbname = 'YOUR DB NAME';
$user = 'YOUR DB USERNAME';
$pass = 'YOUR DB PASSWORD';

// Create a new PDO instance
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    // Set error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Set PDO to use prepared statements
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Create the table if it doesn't exist
    createNavigationTestResultsTable($pdo);
} catch (PDOException $e) {
    // Log the error
    error_log($e->getMessage());
    echo "Database connection failed.";
    exit;
}

// Function to create the results table
function createNavigationTestResultsTable($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS navigation_test_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(255),
        image_id INT NOT NULL,
        clicked_inside_hotspot TINYINT(1) NOT NULL,
        x INT NOT NULL,
        y INT NOT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        difficulty INT,
        unclear_step TEXT,
        expected_but_missing TEXT
    )";
    $pdo->exec($sql);
}

// Initialize variables
$message = '';
$has_completed_test = isset($_SESSION['has_completed_test']) && $_SESSION['has_completed_test'] === true;

// Fetch images and hotspots from the database ordered by position
$stmt = $pdo->prepare("SELECT * FROM navigation_test_images ORDER BY position ASC");
$stmt->execute();
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if images are available
if (empty($images)) {
    // Redirect to upload.php if no images are found
    header("Location: upload.php");
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['x']) && isset($_POST['y']) && isset($_POST['image_index'])) {
        // User clicked on the image
        $x = intval($_POST['x']);
        $y = intval($_POST['y']);
        $image_index = intval($_POST['image_index']);

        // Get the current image hotspot
        $current_image = $images[$image_index];

        // Calculate min and max values of hotspot coordinates
        $hotspot_x_min = min($current_image['hotspot_x1'], $current_image['hotspot_x2']);
        $hotspot_x_max = max($current_image['hotspot_x1'], $current_image['hotspot_x2']);
        $hotspot_y_min = min($current_image['hotspot_y1'], $current_image['hotspot_y2']);
        $hotspot_y_max = max($current_image['hotspot_y1'], $current_image['hotspot_y2']);

        // Check if click is within hotspot
        if ($x >= $hotspot_x_min && $x <= $hotspot_x_max && $y >= $hotspot_y_min && $y <= $hotspot_y_max) {
            $clicked_inside_hotspot = 1;
            // Advance to next image
            $image_index++;
            $_SESSION['image_index'] = $image_index;
        } else {
            $clicked_inside_hotspot = 0;
            // Go to post-test questions
            $_SESSION['show_post_test'] = true;
        }

        // Store the result in the database
        $stmt = $pdo->prepare("INSERT INTO navigation_test_results (session_id, image_id, clicked_inside_hotspot, x, y) VALUES (:session_id, :image_id, :clicked_inside_hotspot, :x, :y)");
        $stmt->execute([
            'session_id' => session_id(),
            'image_id' => $current_image['id'],
            'clicked_inside_hotspot' => $clicked_inside_hotspot,
            'x' => $x,
            'y' => $y
        ]);

        if ($image_index >= count($images)) {
            // All images completed, go to post-test
            $_SESSION['show_post_test'] = true;
        }

    } elseif (isset($_POST['difficulty'])) {
        // User submitted the post-test questions
        $difficulty = intval($_POST['difficulty']);
        $unclear_step = htmlspecialchars(trim($_POST['unclear_step']), ENT_QUOTES, 'UTF-8');
        $expected_missing = htmlspecialchars(trim($_POST['expected_missing']), ENT_QUOTES, 'UTF-8');

        // Store the post-test answers in the database
        $stmt = $pdo->prepare("INSERT INTO navigation_test_results (session_id, image_id, difficulty, unclear_step, expected_but_missing) VALUES (:session_id, -1, :difficulty, :unclear_step, :expected_missing)");
        $stmt->execute([
            'session_id' => session_id(),
            'difficulty' => $difficulty,
            'unclear_step' => $unclear_step,
            'expected_missing' => $expected_missing
        ]);

        $message = "Thank you for completing the test!";
        $_SESSION['has_completed_test'] = true;
        session_regenerate_id(true);
        $has_completed_test = true;
    }
}

// Check if the test is already completed
if (!isset($_SESSION['image_index'])) {
    $_SESSION['image_index'] = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Navigation Test</title>
    <!-- Include Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
    <style>
        /* Reset and basic styling */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Roboto', sans-serif;
            color: #333;
            background-color: #f9f9f9;
            overflow-x: hidden;
        }
        .container {
            max-width: 800px;
            margin: 50px auto;
            padding: 0 20px;
            text-align: center;
        }
        h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }
        .header {
            padding: 0 0 20px 0;
        }
        .start-button, .submit-button {
            background-color: #3498db;
            color: #fff;
            padding: 15px 30px;
            border: none;
            border-radius: 25px;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.3s;
            margin-top: 20px;
        }
        .start-button:hover, .submit-button:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }
        .start-button:disabled, .submit-button:disabled {
            background-color: #bdc3c7;
            cursor: not-allowed;
            transform: none;
        }
        .image-container {
            margin-top: 30px;
            max-height: calc(100vh - 150px);
            overflow: hidden;
        }
        .image-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 12px;
            cursor: pointer;
        }
        .question-form {
            display: none;
            margin-top: 30px;
            text-align: left;
        }
        .question-form input[type="text"],
        .question-form textarea {
            padding: 10px;
            width: 100%;
            font-size: 1em;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .question-form label {
            font-size: 1.1em;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        .message {
            font-size: 1.2em;
            color: #2c3e50;
            margin-top: 30px;
        }
        /* Styles for the rating scale */
        .rating-scale {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
        }
        .rating-scale input[type="radio"] {
            display: none;
        }
        .rating-scale label {
            background-color: #ccc;
            color: #333;
            padding: 10px 15px;
            margin: 5px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-size: 1em;
            user-select: none;
        }
        .rating-scale input[type="radio"]:checked + label {
            background-color: #3498db;
            color: #fff;
        }
        .rating-scale label:hover {
            background-color: #2980b9;
            color: #fff;
        }
        .cta {
            margin-top: 20px;
            font-size: 1.1em;
            color: #2c3e50;
        }
        .cta a {
            color: #3498db;
            text-decoration: none;
            font-weight: bold;
        }
        .cta a:hover {
            text-decoration: underline;
        }
        /* Spacing for form elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 1.1em;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        /* Wrapper for rating scale */
        .rating-scale-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 10px;
        }

        .rating-label {
            font-weight: bold;
            color: #2c3e50;
        }

        .rating-label-left {
            margin-right: 15px;
        }

        .rating-label-right {
            margin-left: 15px;
        }

        .rating-scale {
            display: flex;
        }

        .rating-scale input[type="radio"] {
            display: none;
        }

        .rating-scale label {
            background-color: #ccc;
            color: #333;
            padding: 10px 15px;
            margin: 0 3px; /* Adjust spacing between rating labels */
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
            font-size: 1em;
            user-select: none;
        }

        .rating-scale input[type="radio"]:checked + label {
            background-color: #3498db;
            color: #fff;
        }

        .rating-scale label:hover {
            background-color: #2980b9;
            color: #fff;
        }

        /* Adjust textarea styles */
        textarea {
            width: 100%;
            max-width: 100%;
            padding: 15px;
            font-size: 1em;
            border: 1px solid #ccc;
            border-radius: 5px;
            resize: vertical;
            min-height: 80px;
            box-sizing: border-box;
            font-family: 'Roboto', sans-serif;
        }
        /* Centering the submit button */
        .question-form {
            display: flex;
            flex-direction: column;
            align-items: center; /* Centers all form elements */
        }

        .submit-button {
            background-color: #3498db;
            color: #fff;
            padding: 15px 30px;
            border: none;
            border-radius: 25px;
            font-size: 1em;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.3s;
            margin-top: 20px;
            text-align: center;
        }

        .submit-button:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }

        .submit-button:disabled {
            background-color: #bdc3c7;
            cursor: not-allowed;
            transform: none;
        }
    </style>
    <script>
        function captureClick(event) {
            var rect = event.target.getBoundingClientRect();
            var x = event.clientX - rect.left; // x position within the element.
            var y = event.clientY - rect.top;  // y position within the element.

            // Adjust for image scaling
            var scaleX = event.target.naturalWidth / event.target.width;
            var scaleY = event.target.naturalHeight / event.target.height;

            x = x * scaleX;
            y = y * scaleY;

            // Set the values in the hidden form inputs
            document.getElementById('x').value = Math.round(x);
            document.getElementById('y').value = Math.round(y);

            // Submit the form
            document.getElementById('click-form').submit();
        }
    </script>
</head>
<body>
<div class="container">
    <?php if ($has_completed_test): ?>
        <h1>Thank you for completing the test!</h1>
        <p class="cta">Interested in getting paid to complete usability tests? <a href="https://userble.com/become-a-tester" target="_blank">Become a tester for Userble</a>.</p>
    <?php else: ?>
        <?php if (isset($_SESSION['show_post_test']) && $_SESSION['show_post_test']): ?>
            <h1>Final Questions</h1>
            <?php if (!empty($message)) { echo "<p class='message'>" . $message . "</p>"; } ?>
            <form id="question-form" class="question-form" method="post">
                <div class="form-group">
                    <label class="form-label">1. On a scale of 1-10, how difficult or easy was it to complete the instructions?</label>
                    <div class="rating-scale-wrapper">
                        <span class="rating-label rating-label-left">Hard</span>
                        <div class="rating-scale" id="difficulty">
                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                <input type="radio" name="difficulty" value="<?php echo $i; ?>" id="difficulty_<?php echo $i; ?>" required>
                                <label for="difficulty_<?php echo $i; ?>"><?php echo $i; ?></label>
                            <?php endfor; ?>
                        </div>
                        <span class="rating-label rating-label-right">Easy</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="unclear_step">2. Was there any step that seemed unclear?</label>
                    <textarea name="unclear_step" id="unclear_step" rows="4" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label" for="expected_missing">3. Is there anything you expected to see that wasn’t there?</label>
                    <textarea name="expected_missing" id="expected_missing" rows="4" required></textarea>
                </div>
                <button type="submit" class="submit-button">Submit Answers</button>
            </form>


            <script>
                // Show the question form immediately
                document.getElementById('question-form').style.display = 'flex';
            </script>
        <?php else: ?>
            <?php
            $image_index = $_SESSION['image_index'];
            if ($image_index >= count($images)) {
                // All images completed, go to post-test
                $_SESSION['show_post_test'] = true;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
            $current_image = $images[$image_index];
            ?>
            <header class="header">
                <h1>Instructions:</h1>
                <p><?php echo htmlspecialchars($current_image['instruction']); ?></p>
            </header>

            <div id="image-container" class="image-container">
                <form id="click-form" method="post">
                    <input type="hidden" name="x" id="x">
                    <input type="hidden" name="y" id="y">
                    <input type="hidden" name="image_index" value="<?php echo $image_index; ?>">
                    <img src="<?php echo htmlspecialchars($current_image['image_name']); ?>" alt="Test Image" onclick="captureClick(event)">
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
