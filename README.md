# Preference Test README

## Overview
This Navigation Test is designed for usability testing, specifically for evaluating user interactions with navigation elements in images. It captures where users click, compares these against predefined hotspots, and collects post-test feedback. Created by https://www.userble.org - Open Source Usability Testing.

### Setup

**Edit Database Credentials:** Update the following variables with your MySQL credentials in the script:

$host = 'localhost';
$dbname = 'YOUR DB NAME';
$user = 'YOUR DB USERNAME';
$pass = 'YOUR DB PASSWORD';

**Database Table Creation:** The script automatically creates the necessary database tables if they don't exist:

* **navigation_test_images** Contains the images and their respective instructions and hotspot coordinates.*
* **navigation_test_results** Records the test results, including clicks, session information, and feedback.

**- Security:** This tool uses security headers to prevent common vulnerabilities.

### Usage

**Image Upload:** Ensure images and hotspot data are uploaded through upload.php.

**Navigation Test:** Users click on specific parts of the images based on given instructions.

**Post-Test Questions:** Once all images are tested, users complete a brief questionnaire.

**Restrictions:** Limits users to one vote per session and up to 3 votes per hour from the same IP address.

### Features
**Session Management:** Each user session is tracked to prevent duplicate test entries.

**Click Tracking:** Captures exact coordinates of user clicks and checks if the click falls inside the defined hotspot.

**Post-Test Feedback:** Collects information on the difficulty, unclear steps, and any missing expectations.

### Contributions
Contributions are welcome! If you'd like to enhance the project or fix bugs, please submit a pull request or open an issue.

### Requirements
Works on most shared / managed hosting.
PHP 7.4+
MySQL

### License
This project is free and open source, available under The ILO's Open License (https://www.theilo.org).
