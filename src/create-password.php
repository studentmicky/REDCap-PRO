<?php

// Initialize Authentication
$module::$AUTH->init();

# Parse query string to grab token.
parse_str($_SERVER['QUERY_STRING'], $qstring);

// Redirect to login page if we shouldn't be here
if (!isset($qstring["t"]) && $_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: " . $module->getUrl("src/login.php", true));
    return;
}

// Define variables and initialize with empty values
$new_password = $confirm_password = "";
$new_password_err = $confirm_password_err = "";


// Verify password reset token
$verified_user = $module::$PARTICIPANT->verifyPasswordResetToken($qstring["t"]);

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate token
    if (!$module::$AUTH->validate_csrf_token($_POST['token'])) {
        $module::$UI->ShowParticipantHeader('Error');
        echo "<div style='text-align: center;'>Oops! Something went wrong.<br>Do you have multiple tabs open?</div>";
        $module::$UI->EndParticipantPage();
        return;
    }

    // Validate password
    $new_password = trim($_POST["new_password"]);
    if (empty($new_password)) {
        $new_password_err = "Please enter your new password.";
        $any_error = TRUE;
    } else {
        // Validate password strength
        $pw_len_req   = $module::$SETTINGS->getPasswordLength();
        $uppercase    = preg_match('@[A-Z]@', $new_password);
        $lowercase    = preg_match('@[a-z]@', $new_password);
        $number       = preg_match('@[0-9]@', $new_password);
        $specialChars = preg_match('@[^\w]@', $new_password);
        $goodLength   = strlen($new_password) >= $pw_len_req;

        if (!$uppercase || !$lowercase || !$number || !$specialChars || !$goodLength) {
            $new_password_err = "Password should be at least ${pw_len_req} characters in length and should include at least one of each of the following: 
            <br>- upper-case letter
            <br>- lower-case letter
            <br>- number
            <br>- special character (non-alphanumeric)";
            $any_error = TRUE;
        }
    }

    // Validate confirm password
    $confirm_password = trim($_POST["confirm_password"]);
    if (empty($confirm_password)) {
        $confirm_password_err = "Please confirm the password.";
        $any_error = TRUE;
    } else {
        if (empty($new_password_err) && ($new_password !== $confirm_password)) {
            $confirm_password_err = "Password did not match.";
            $any_error = TRUE;
        }
    }

    // Check input errors before updating the database
    if (!$any_error) {

        // Grab all user details
        $participant = $module::$PARTICIPANT->getParticipant($_POST["username"]);

        // Update password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $result = $module::$PARTICIPANT->storeHash($password_hash, $participant["log_id"]);
        if (empty($result) || $result === FALSE) {
            echo "Oops! Something went wrong. Please try again later.";
            return;
        }

        // Password was successfully set. Expire the token.
        $module::$PARTICIPANT->expirePasswordResetToken($participant["log_id"]);

        // Store data in session variables
        $module::$AUTH->set_login_values($participant);

        $module::$UI->ShowParticipantHeader("Password Successfully Set");
        echo "<div style='text-align:center;'><p>You may now close this tab.</p></div>";
        $module::$UI->EndParticipantPage();
        return;
    }
}

// set csrf token
$module::$AUTH->set_csrf_token();

$module::$UI->ShowParticipantHeader("Create Password");

if ($verified_user) {

    $module->log("Participant opened create password page", [
        "rcpro_username" => $verified_user["rcpro_username"]
    ]);

    echo "<p>Please fill out this form to create your password.</p>";
?>
    <form action="<?= $module->getUrl("src/create-password.php", true) . "&t=" . urlencode($qstring["t"]); ?>" method="post">
        <div class="form-group">
            <span>Username: <span style="color: #900000; font-weight: bold;"><?= $verified_user["rcpro_username"]; ?></span></span>
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" class="form-control <?php echo (!empty($new_password_err)) ? 'is-invalid' : ''; ?>">
            <span class="invalid-feedback"><?php echo $new_password_err; ?></span>
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>">
            <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
        </div>
        <div class="form-group">
            <input type="submit" class="btn btn-primary" value="Submit">
        </div>
        <input type="hidden" name="token" value="<?= $module::$AUTH->get_csrf_token(); ?>">
        <input type="hidden" name="username" value="<?= $verified_user["rcpro_username"] ?>">
    </form>
    </div>
    </body>

    </html>
<?php } else {
    echo "<div class='red'>Something went wrong. Try requesting a password reset.</div>";
}
