<?php

# Initialize authentication session on page
$module::$AUTH->init();

$module::$UI->ShowParticipantHeader("Forgot Username?");

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate token
    if (!$module::$AUTH->validate_csrf_token($_POST['token'])) {
        $module->log("Invalid CSRF Token");
        echo "Oops! Something went wrong. Please try again later.";
        return;
    }

    $err = null;

    // Validate username
    if (empty(trim($_POST["email"]))) {
        $err = "Please enter your email address.";
    } else {
        $email = \REDCap::escapeHtml(trim($_POST["email"]));
        // Check input errors before sending reset email
        if (!$err) {
            $rcpro_participant_id = $module::$PARTICIPANT->getParticipantIdFromEmail($email);
            if (!empty($rcpro_participant_id)) {
                $username = $module::$PARTICIPANT->getUserName($rcpro_participant_id);
                $module->log("Username Reminder Email Sent", [
                    "rcpro_participant_id" => $rcpro_participant_id,
                    "rcpro_username"       => $username
                ]);
                $module->sendUsernameEmail($email, $username);
            }
            echo "If a user account associated with the supplied email address exists, an email with the account's username was sent to that email address.";
            return;
        }
    }
}

// set csrf token
$module::$AUTH->set_csrf_token();

echo '<div style="text-align: center;"><p>Provide the email address associated with your account.</p></div>';
?>
<form action="<?= $module->getUrl("src/forgot-username.php", true); ?>" method="post">
    <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="email" class="form-control <?php echo (!empty($err)) ? 'is-invalid' : ''; ?>">
        <span class="invalid-feedback"><?php echo $err; ?></span>
    </div>
    <div class="form-group d-grid">
        <input type="submit" class="btn btn-primary" value="Submit">
    </div>
    <input type="hidden" name="token" value="<?= $module::$AUTH->get_csrf_token(); ?>">
</form>
<hr>
<div style="text-align: center;">
    <a href="<?= $module->getUrl("src/forgot-password.php", true); ?>">Forgot Password?</a>
</div>
</div>
<style>
    a {
        text-decoration: none !important;
        color: #900000 !important;
        font-weight: bold !important;
    }

    a:hover {
        text-shadow: 0px 0px 5px #900000;
    }
</style>
</body>

</html>