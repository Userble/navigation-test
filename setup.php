<?php
session_start();

// Security headers
header("Content-Security-Policy: 
    default-src 'self'; 
    style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://code.jquery.com; 
    font-src 'self' https://fonts.gstatic.com; 
    script-src 'self' 'unsafe-inline' https://code.jquery.com
");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: no-referrer");

// Database credentials
$host = 'localhost';
$dbname = 'YOUR DB NAME';
$user = 'YOUR DB USERNAME';
$pass = 'YOUR DB PASSWORD';

// Establish PDO connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    createImageTable($pdo);
} catch (PDOException $e) {
    handleError($e->getMessage(), 'Database connection failed.');
}

// Function to create the images table
function createImageTable($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS navigation_test_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                image_name VARCHAR(255) NOT NULL,
                instruction TEXT NOT NULL,
                hotspot_x1 INT NOT NULL,
                hotspot_y1 INT NOT NULL,
                hotspot_x2 INT NOT NULL,
                hotspot_y2 INT NOT NULL,
                position INT NOT NULL DEFAULT 0,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Ensure 'position' column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM navigation_test_images LIKE 'position'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE navigation_test_images ADD COLUMN position INT NOT NULL DEFAULT 0");
        }
        // Assign positions if all are zero
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM navigation_test_images");
        if ($stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            $positions = $pdo->query("SELECT DISTINCT position FROM navigation_test_images")->fetchAll(PDO::FETCH_COLUMN);
            if (count($positions) === 1 && $positions[0] == 0) {
                $pdo->exec("SET @pos = 0");
                $pdo->exec("UPDATE navigation_test_images SET position = (@pos := @pos + 1) ORDER BY timestamp ASC");
            }
        }
    } catch (PDOException $e) {
        handleError($e->getMessage(), 'Error creating table.');
    }
}

// Unified error handling
function handleError($logMessage, $userMessage) {
    error_log($logMessage);
    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $userMessage]);
    } else {
        echo $userMessage;
    }
    exit;
}

// Check request type and handle accordingly
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        handleGetImageData($pdo);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'upload':
            handleUpload($pdo);
            break;
        // Removed 'edit' case as per requirement
        case 'delete':
            handleDelete($pdo);
            break;
        case 'reorder':
            handleReorder($pdo);
            break;
        default:
            handleError('Invalid action.', 'Invalid action.');
    }
}

// Function to handle GET requests for image data
function handleGetImageData($pdo) {
    header('Content-Type: application/json');
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        try {
            $stmt = $pdo->prepare("SELECT * FROM navigation_test_images WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $image = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($image ? ['success' => true, 'image' => $image] : ['success' => false, 'message' => 'Image not found.']);
            exit; // Terminate script execution
        } catch (PDOException $e) {
            handleError($e->getMessage(), 'Database error.');
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No ID provided.']);
        exit;
    }
}

// Function to handle image uploads
function handleUpload($pdo) {
    $instructions = $_POST['instructions'] ?? [];
    $files = $_FILES['images'] ?? [];
    $hotspots = [
        'x1' => $_POST['hotspot_x1'] ?? [],
        'y1' => $_POST['hotspot_y1'] ?? [],
        'x2' => $_POST['hotspot_x2'] ?? [],
        'y2' => $_POST['hotspot_y2'] ?? []
    ];

    // Validate inputs
    foreach ($hotspots as $key => $values) {
        foreach ($values as $value) {
            if ($value === '' && $value !== '0') {
                handleError('Hotspots not fully set.', 'Please set hotspots for all images before submitting.');
            }
        }
    }

    // Ensure instructions and files are provided
    if (empty($instructions) || empty($files['name'][0])) {
        handleError('Missing data.', 'Please provide instructions and upload at least one image.');
    }

    // Get current max position
    try {
        $stmt = $pdo->query("SELECT MAX(position) as max_pos FROM navigation_test_images");
        $max_pos = (int)($stmt->fetch(PDO::FETCH_ASSOC)['max_pos'] ?? 0);
    } catch (PDOException $e) {
        handleError($e->getMessage(), 'Database error while determining image positions.');
    }

    $uploadSuccess = true;
    $messages = [];

    // Process each uploaded image
    foreach ($files['name'] as $index => $name) {
        if ($name) {
            $instruction = htmlspecialchars(trim($instructions[$index]), ENT_QUOTES, 'UTF-8');
            $imageFileType = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];

            if (!in_array($imageFileType, $allowed_types)) {
                $uploadSuccess = false;
                $messages[] = "Invalid file type for image: $name.";
                continue;
            }

            if (!getimagesize($files['tmp_name'][$index])) {
                $uploadSuccess = false;
                $messages[] = "File is not a valid image: $name.";
                continue;
            }

            $target_dir = "uploads/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $target_file = $target_dir . uniqid() . '.' . $imageFileType;

            if (!move_uploaded_file($files['tmp_name'][$index], $target_file)) {
                $uploadSuccess = false;
                $messages[] = "Error uploading file: $name.";
                continue;
            }

            // Insert into database
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO navigation_test_images 
                    (image_name, instruction, hotspot_x1, hotspot_y1, hotspot_x2, hotspot_y2, position) 
                    VALUES 
                    (:image_name, :instruction, :hotspot_x1, :hotspot_y1, :hotspot_x2, :hotspot_y2, :position)
                ");
                $stmt->execute([
                    'image_name' => $target_file,
                    'instruction' => $instruction,
                    'hotspot_x1' => intval($hotspots['x1'][$index]),
                    'hotspot_y1' => intval($hotspots['y1'][$index]),
                    'hotspot_x2' => intval($hotspots['x2'][$index]),
                    'hotspot_y2' => intval($hotspots['y2'][$index]),
                    'position' => ++$max_pos
                ]);
            } catch (PDOException $e) {
                $uploadSuccess = false;
                $messages[] = "Database error for image: $name.";
                error_log("DB Insert Error: " . $e->getMessage());
            }
        }
    }

    if ($uploadSuccess) {
        $response = ['success' => true, 'message' => "✅ Images uploaded and hotspots set successfully! Please secure this upload.php file."];
    } else {
        $response = ['success' => false, 'message' => implode('<br>', $messages)];
    }

    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit; // Terminate script execution
    } else {
        // Redirect or handle non-AJAX response as needed
        header("Location: " . $_SERVER['PHP_SELF'] . "?message=" . urlencode($response['message']));
        exit;
    }
}

// Removed handleEdit function as per requirement

// Function to handle image deletions
function handleDelete($pdo) {
    $id = intval($_POST['delete_id'] ?? 0);
    if ($id) {
        try {
            $stmt = $pdo->prepare("SELECT image_name, position FROM navigation_test_images WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $image = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($image) {
                if (file_exists($image['image_name'])) unlink($image['image_name']);
                $pdo->prepare("DELETE FROM navigation_test_images WHERE id = :id")->execute(['id' => $id]);
                $pdo->prepare("UPDATE navigation_test_images SET position = position - 1 WHERE position > :deleted_position")->execute(['deleted_position' => intval($image['position'])]);
                echo json_encode(['success' => true, 'message' => '✅ Image deleted successfully!']);
                exit; // Terminate script execution
            } else {
                handleError('Image not found.', 'Image not found.');
            }
        } catch (PDOException $e) {
            handleError($e->getMessage(), 'Database error while deleting image.');
        }
    } else {
        handleError('Invalid delete request.', 'Invalid delete request.');
    }
}

// Function to handle image reordering
function handleReorder($pdo) {
    if (isAjaxRequest() && isset($_POST['order']) && is_array($_POST['order'])) {
        $order = array_map('intval', $_POST['order']);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE navigation_test_images SET position = :position WHERE id = :id");
            foreach ($order as $index => $id) {
                $stmt->execute(['position' => $index + 1, 'id' => $id]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => '✅ Image order updated successfully.']);
            exit; // Terminate script execution
        } catch (PDOException $e) {
            $pdo->rollBack();
            handleError($e->getMessage(), 'Failed to update image order.');
        }
    } else {
        handleError('Invalid reorder request.', 'Invalid reorder request.');
    }
}

// Function to fetch all uploaded images
function fetchAllImages($pdo) {
    try {
        return $pdo->query("SELECT * FROM navigation_test_images ORDER BY position ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database fetch failed: " . $e->getMessage());
        return [];
    }
}

$all_images = fetchAllImages($pdo);

// Function to check if the request is AJAX
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Fetch message from GET parameters if available
$message = $_GET['message'] ?? '';
$upload_success = strpos($message, '✅') !== false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Images and Set Hotspots</title>
    <!-- Google Fonts & jQuery UI CSS -->
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.0/themes/base/jquery-ui.css">
    <style>
        /* Consolidated CSS */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Roboto', sans-serif; color: #333; background: #f9f9f9; overflow-x: hidden; }
        .container { max-width: 1200px; margin: 50px auto; padding: 0 20px; text-align: center; }
        h1 { font-size: 2em; margin-bottom: 10px; }
        .header p { margin-bottom: 20px; }
        .message, .success-message, .error-message { font-size: 1.2em; margin-top: 30px; }
        .success-message { color: #27ae60; }
        .error-message { color: #e74c3c; }
        form.upload-form, .manage-section { margin-top: 30px; text-align: left; }
        form.upload-form input[type="file"], form.upload-form textarea { padding: 10px; width: 100%; max-width: 400px; margin-bottom: 20px; font-size: 1em; border: 1px solid #ccc; border-radius: 5px; }
        form.upload-form label { font-size: 1.1em; margin-bottom: 10px; display: block; color: #2c3e50; }
        .submit-button { background: #3498db; color: #fff; padding: 15px 30px; border: none; border-radius: 25px; font-size: 1em; cursor: not-allowed; transition: background 0.3s, transform 0.3s; margin-top: 20px; }
        .submit-button.enabled { cursor: pointer; background: #3498db; }
        .submit-button.enabled:hover { background: #2980b9; transform: translateY(-2px); }
        .image-preview img { max-width: 100%; border-radius: 12px; cursor: crosshair; user-select: none; }
        .hotspot-box { position: absolute; border: 2px dashed red; display: none; pointer-events: none; }
        .relative { position: relative; display: inline-block; }
        .image-section { border: 1px solid #ccc; padding: 20px; margin-bottom: 30px; border-radius: 8px; background: #fff; text-align: left; position: relative; }
        .add-image-button { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 50%; 
            font-size: 1.5em; 
            cursor: pointer; 
            transition: background 0.3s, transform 0.3s; 
            background: #2ecc71; 
            color: #fff; 
            width: 50px; 
            height: 50px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 20px auto 0; /* Center the button and add top margin */
        }
        .add-image-button:hover { background: #27ae60; transform: translateY(-2px); }
        /* Step Indicator */
        .step-indicator ul { list-style: none; display: flex; gap: 10px; }
        .step-indicator li { position: relative; padding-left: 25px; }
        .step-indicator li::before { content: '●'; position: absolute; left: 0; top: 0; font-size: 1.2em; }
        .step-indicator li.completed { color: #2ecc71; }
        .step-indicator li.completed::before { content: '✔'; }
        /* Manage Section */
        .manage-section h2 { margin-bottom: 20px; }
        .manage-images { display: flex; flex-wrap: wrap; gap: 20px; cursor: move; }
        .manage-image { border: 1px solid #ccc; padding: 15px; border-radius: 8px; background: #fff; width: calc(33.333% - 40px); position: relative; }
        .manage-image .relative { position: relative; display: inline-block; }
        .manage-image img { max-width: 100%; border-radius: 8px; }
        p.margin-bottom { margin-bottom: 20px;}
        .hotspot-overlay { 
            position: absolute; 
            border: 2px dashed red; 
            pointer-events: none; 
            box-sizing: border-box; 
            /* Background with transparency for better visibility */
            background: rgba(255, 0, 0, 0.2); 
        }
        .actions { margin-top: 10px; display: flex; justify-content: space-between; }
        .actions button { padding: 8px 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 0.9em; }
        /* Removed Edit Modal Styles */
        /* Remove Section Button */
        .remove-section-button {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #e74c3c;
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            font-size: 1.2em;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s;
            z-index: 10;
        }

        .remove-section-button:hover {
            background: #c0392b;
        }
        /* Responsive Design */
        @media (max-width: 768px) {
            .manage-image { width: calc(50% - 40px); }
        }
        @media (max-width: 480px) {
            .manage-image { width: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="header">
        <h1>Manage Images and Set Hotspots</h1>
        <p>Upload images, set hotspots, and manage your uploads by deleting or reordering as needed.</p>
    </header>

    <?php if ($message): ?>
        <p class="<?= $upload_success ? 'success-message' : (preg_match('/error|fail/i', $message) ? 'error-message' : 'message') ?>">
            <?= htmlspecialchars($message) ?>
        </p>
    <?php endif; ?>

    <!-- Upload Form -->
    <form class="upload-form" method="post" enctype="multipart/form-data" id="upload-form">
        <input type="hidden" name="action" value="upload">
        <div id="image-sections">
            <div class="image-section" data-index="0" style="position: relative;">
                <button type="button" class="remove-section-button">&times;</button>
                <div class="step-indicator" id="step-indicator_0">
                    <ul>
                        <li id="instruction-step_0">Instruction</li>
                        <li id="upload-step_0">Image Upload</li>
                        <li id="hotspot-step_0">Hotspot</li>
                    </ul>
                </div>

                <label for="instruction_0">Instruction for the user:</label>
                <textarea name="instructions[]" id="instruction_0" required></textarea>

                <label for="image_0">Select image to upload:</label>
                <input type="file" name="images[]" id="image_0" accept="image/*" required>

                <p class="instructions">Click and drag on the image to draw the hotspot area.</p>

                <div id="image-preview_0" class="image-preview" style="display:none;">
                    <div class="relative">
                        <img id="uploaded-image_0" src="" alt="Uploaded Image">
                        <div id="hotspot_0" class="hotspot-box"></div>
                    </div>
                </div>
                <input type="hidden" name="hotspot_x1[]" id="hotspot_x1_0">
                <input type="hidden" name="hotspot_y1[]" id="hotspot_y1_0">
                <input type="hidden" name="hotspot_x2[]" id="hotspot_x2_0">
                <input type="hidden" name="hotspot_y2[]" id="hotspot_y2_0">
            </div>
        </div>
        <!-- Moved the Add Image button below the image sections and styled it as a medium-sized "+" button -->
        <button type="button" class="add-image-button" id="add-image-button">+</button>
        <button type="submit" class="submit-button" id="submit-button" disabled>Upload and Set Hotspots</button>
    </form>

    <!-- Manage Uploaded Images -->
    <div class="manage-section">
        <h2>Uploaded Images</h2>
        <?php if (empty($all_images)): ?>
            <p>No images uploaded yet.</p>
        <?php else: ?>
            <p class="margin-bottom">Click and drag to reorder.</p>
            <div class="manage-images" id="manage-images">
                <?php foreach ($all_images as $image): ?>
                    <div class="manage-image" data-id="<?= $image['id'] ?>">
                        <div class="relative">
                            <img src="<?= htmlspecialchars($image['image_name']) ?>" alt="Uploaded Image">
                            <!-- Overlay the hotspot box based on stored coordinates -->
                            <div class="hotspot-overlay" style="
                                left: <?= $image['hotspot_x1'] / getImageWidth($image['image_name']) * 100 ?>%;
                                top: <?= $image['hotspot_y1'] / getImageHeight($image['image_name']) * 100 ?>%;
                                width: <?= ($image['hotspot_x2'] - $image['hotspot_x1']) / getImageWidth($image['image_name']) * 100 ?>%;
                                height: <?= ($image['hotspot_y2'] - $image['hotspot_y1']) / getImageHeight($image['image_name']) * 100 ?>%;
                            "></div>
                        </div>
                        <p><strong>Instruction:</strong> <?= htmlspecialchars($image['instruction']) ?></p>
                        <p><strong>Hotspot:</strong> (<?= $image['hotspot_x1'] ?>, <?= $image['hotspot_y1'] ?>) to (<?= $image['hotspot_x2'] ?>, <?= $image['hotspot_y2'] ?>)</p>
                        <div class="actions">
                            <!-- Removed Edit Button as per requirement -->
                            <form method="post" class="delete-form" data-id="<?= $image['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= $image['id'] ?>">
                                <button type="submit" class="delete-button">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <!-- The Add Image button is already present above the submit button -->
            </div>
        <?php endif; ?>
    </div>

    <!-- Removed Edit Modal as per requirement -->

</div>

<!-- Include jQuery and jQuery UI -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/ui/1.13.0/jquery-ui.min.js" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    let imageIndex = 0;
    const hotspotsSet = {};
    const instructionsSet = {};
    const imagesUploaded = {};
    const submitButton = document.getElementById('submit-button');

    // Initialize sortable
    $("#manage-images").sortable({
        update: function() {
            const order = $('.manage-image').map((_, el) => $(el).data('id')).get();
            $.post('', { action: 'reorder', order }, (response) => {
                alert(response.message);
                if (response.success) {
                    // Optionally reload the page to reflect changes
                    // location.reload();
                }
            }, 'json').fail(() => {
                alert('❌ An error occurred while updating image order.');
            });
        }
    }).disableSelection();

    // Handle Add Image
    document.getElementById('add-image-button').addEventListener('click', () => {
        imageIndex++;
        const imageSections = document.getElementById('image-sections');
        const newSection = document.createElement('div');
        newSection.className = 'image-section';
        newSection.dataset.index = imageIndex;
        newSection.style.position = 'relative'; // Ensure positioning for the "X" button
        newSection.innerHTML = `
            <button type="button" class="remove-section-button">&times;</button>
            <div class="step-indicator" id="step-indicator_${imageIndex}">
                <ul>
                    <li id="instruction-step_${imageIndex}">Instruction</li>
                    <li id="upload-step_${imageIndex}">Image Upload</li>
                    <li id="hotspot-step_${imageIndex}">Hotspot</li>
                </ul>
            </div>

            <label for="instruction_${imageIndex}">Instruction for the user:</label>
            <textarea name="instructions[]" id="instruction_${imageIndex}" required></textarea>

            <label for="image_${imageIndex}">Select image to upload:</label>
            <input type="file" name="images[]" id="image_${imageIndex}" accept="image/*" required>

            <p class="instructions">Click and drag on the image to draw the hotspot area.</p>

            <div id="image-preview_${imageIndex}" class="image-preview" style="display:none;">
                <div class="relative">
                    <img id="uploaded-image_${imageIndex}" src="" alt="Uploaded Image">
                    <div id="hotspot_${imageIndex}" class="hotspot-box"></div>
                </div>
            </div>
            <input type="hidden" name="hotspot_x1[]" id="hotspot_x1_${imageIndex}">
            <input type="hidden" name="hotspot_y1[]" id="hotspot_y1_${imageIndex}">
            <input type="hidden" name="hotspot_x2[]" id="hotspot_x2_${imageIndex}">
            <input type="hidden" name="hotspot_y2[]" id="hotspot_y2_${imageIndex}">
        `;
        imageSections.appendChild(newSection);

        // Event listeners for new inputs
        const imageInput = document.getElementById(`image_${imageIndex}`);
        const instructionInput = document.getElementById(`instruction_${imageIndex}`);
        imageInput.addEventListener('change', (e) => previewImage(e, imageIndex));
        instructionInput.addEventListener('input', () => {
            if (instructionInput.value.trim()) {
                instructionsSet[imageIndex] = true;
                updateStepIndicator(imageIndex, 'instruction');
            } else {
                instructionsSet[imageIndex] = false;
                updateStepIndicator(imageIndex, 'instruction', false);
            }
            toggleSubmitButton();
        });
    });

    // Handle initial image section
    document.getElementById('image_0').addEventListener('change', (e) => previewImage(e, 0));
    document.getElementById('instruction_0').addEventListener('input', function() {
        if (this.value.trim()) {
            instructionsSet[0] = true;
            updateStepIndicator(0, 'instruction');
        } else {
            instructionsSet[0] = false;
            updateStepIndicator(0, 'instruction', false);
        }
        toggleSubmitButton();
    });

    // Preview Image and Initialize Hotspot Drawing
    function previewImage(event, index) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => {
            const img = document.getElementById(`uploaded-image_${index}`);
            img.src = reader.result;
            document.getElementById(`image-preview_${index}`).style.display = 'block';
            img.onload = () => {
                initDrawing(index);
                imagesUploaded[index] = true;
                updateStepIndicator(index, 'upload');
                toggleSubmitButton();
            };
        };
        reader.readAsDataURL(file);
    }

    // Initialize Hotspot Drawing with Click-Drag
    function initDrawing(index) {
        const img = document.getElementById(`uploaded-image_${index}`);
        const hotspot = document.getElementById(`hotspot_${index}`);
        let isDrawing = false, startX, startY, currentX, currentY, scaleX, scaleY;

        img.addEventListener('mousedown', (e) => {
            e.preventDefault(); // Prevent default drag behavior
            isDrawing = true;

            const rect = img.getBoundingClientRect();
            scaleX = img.naturalWidth / rect.width;
            scaleY = img.naturalHeight / rect.height;
            startX = Math.round((e.clientX - rect.left) * scaleX);
            startY = Math.round((e.clientY - rect.top) * scaleY);

            hotspot.style.left = `${startX / scaleX}px`;
            hotspot.style.top = `${startY / scaleY}px`;
            hotspot.style.width = '0px';
            hotspot.style.height = '0px';
            hotspot.style.display = 'block';
        });

        img.addEventListener('mousemove', (e) => {
            if (!isDrawing) return;

            const rect = img.getBoundingClientRect();
            currentX = Math.round((e.clientX - rect.left) * scaleX);
            currentY = Math.round((e.clientY - rect.top) * scaleY);

            const width = (currentX - startX) / scaleX;
            const height = (currentY - startY) / scaleY;

            hotspot.style.width = `${Math.abs(width)}px`;
            hotspot.style.height = `${Math.abs(height)}px`;
            hotspot.style.left = `${width < 0 ? currentX / scaleX : startX / scaleX}px`;
            hotspot.style.top = `${height < 0 ? currentY / scaleY : startY / scaleY}px`;
        });

        img.addEventListener('mouseup', (e) => {
            if (!isDrawing) return;
            isDrawing = false;

            const rect = img.getBoundingClientRect();
            currentX = Math.round((e.clientX - rect.left) * scaleX);
            currentY = Math.round((e.clientY - rect.top) * scaleY);

            document.getElementById(`hotspot_x1_${index}`).value = Math.min(startX, currentX);
            document.getElementById(`hotspot_y1_${index}`).value = Math.min(startY, currentY);
            document.getElementById(`hotspot_x2_${index}`).value = Math.max(startX, currentX);
            document.getElementById(`hotspot_y2_${index}`).value = Math.max(startY, currentY);

            hotspotsSet[index] = true;
            updateStepIndicator(index, 'hotspot');
            toggleSubmitButton();
        });

        // Optional: Handle mouse leaving the image while drawing
        img.addEventListener('mouseleave', (e) => {
            if (isDrawing) {
                isDrawing = false;

                const rect = img.getBoundingClientRect();
                currentX = Math.round((e.clientX - rect.left) * scaleX);
                currentY = Math.round((e.clientY - rect.top) * scaleY);

                document.getElementById(`hotspot_x1_${index}`).value = Math.min(startX, currentX);
                document.getElementById(`hotspot_y1_${index}`).value = Math.min(startY, currentY);
                document.getElementById(`hotspot_x2_${index}`).value = Math.max(startX, currentX);
                document.getElementById(`hotspot_y2_${index}`).value = Math.max(startY, currentY);

                hotspotsSet[index] = true;
                updateStepIndicator(index, 'hotspot');
                toggleSubmitButton();
            }
        });
    }

    // Update Step Indicator
    function updateStepIndicator(index, step, completed = true) {
        const stepElement = document.getElementById(`${step}-step_${index}`);
        if (stepElement) {
            stepElement.classList.toggle('completed', completed);
        }
    }

    // Toggle Submit Button
    function toggleSubmitButton() {
        updateSubmitButtonState();
    }

    // Function to update the submit button state
    function updateSubmitButtonState() {
        const sections = document.querySelectorAll('.image-section');
        const completed = Array.from(sections).every(section => {
            const index = parseInt(section.dataset.index);
            return instructionsSet[index] && imagesUploaded[index] && hotspotsSet[index];
        });
        submitButton.disabled = !completed;
        submitButton.classList.toggle('enabled', completed);
    }


    // Handle Remove Section Button Click
    document.getElementById('image-sections').addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('remove-section-button')) {
            const section = e.target.closest('.image-section');
            const index = parseInt(section.getAttribute('data-index'));

            // Remove the section from the DOM
            section.remove();

            // Remove corresponding data from tracking objects
            delete instructionsSet[index];
            delete imagesUploaded[index];
            delete hotspotsSet[index];

            // Update the submit button state
            updateSubmitButtonState();
        }
    });

    // Removed Edit Modal and related JavaScript as per requirement

    // Handle Delete Forms
    document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (confirm('Are you sure you want to delete this image?')) {
                const formData = new FormData(form);
                $.ajax({
                    url: '',
                    type: 'POST',
                    data: Object.fromEntries(formData.entries()),
                    dataType: 'json',
                    success: (response) => {
                        alert(response.message);
                        if (response.success) {
                            location.reload();
                        }
                    },
                    error: () => alert('❌ An error occurred while deleting the image.')
                });
            }
        });
    });
});
</script>
</body>
</html>

<?php
// Helper functions to get image dimensions
function getImageWidth($image_path) {
    $size = getimagesize($image_path);
    return $size ? $size[0] : 1; // Avoid division by zero
}

function getImageHeight($image_path) {
    $size = getimagesize($image_path);
    return $size ? $size[1] : 1; // Avoid division by zero
}
?>
