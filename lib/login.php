<?php
// login.php -- HotCRP login helpers
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class LoginHelper {
    /** @var bool */
    const DEBUG = false;

    static function check_http_auth(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        assert($conf->opt("httpAuthLogin") !== null);

        // if user signed out of HTTP authentication, send a reauth request
        if (isset($_SESSION["reauth"])) {
            unset($_SESSION["reauth"]);
            header("HTTP/1.0 401 Unauthorized");
            if (is_string($conf->opt("httpAuthLogin"))) {
                header("WWW-Authenticate: " . $conf->opt("httpAuthLogin"));
            } else {
                header("WWW-Authenticate: Basic realm=\"HotCRP\"");
            }
            exit;
        }

        // if user is still valid, OK
        if ($user->has_account_here()) {
            return;
        }

        // check HTTP auth
        if (!isset($_SERVER["REMOTE_USER"]) || !$_SERVER["REMOTE_USER"]) {
            header("HTTP/1.0 401 Unauthorized");
            $conf->header("Error", "home");
            $conf->feedback_msg([
                MessageItem::error("<0>Authentication required"),
                MessageItem::inform("<0>This site is using HTTP authentication to manage its users, but you have not provided authentication data. This usually indicates a server configuration error.")
            ]);
            $conf->footer();
            exit;
        }
        $qreq->email = $_SERVER["REMOTE_USER"];
        if (validate_email($qreq->email)) {
            $qreq->preferredEmail = $qreq->email;
        } else if (($x = $conf->opt("defaultEmailDomain"))
                   && validate_email($qreq->email . "@" . $x)) {
            $qreq->preferredEmail = $qreq->email . "@" . $x;
        }

        $info = self::login_info($conf, $qreq); // XXX
        if ($info["ok"]) {
            $conf->redirect($info["redirect"] ?? "");
        } else {
            header("HTTP/1.0 401 Unauthorized");
            $conf->header("Error", "home");
            $conf->feedback_msg([
                MessageItem::error("<0>Authentication error"),
                MessageItem::inform("<0>This site is using HTTP authentication to manage its users. You have provided incorrect authentication data.")
            ]);
            $conf->footer();
            exit;
        }
    }

    /** @return array{ok:true,user:Contact}|array{ok:false} */
    static private function user_lookup(Conf $conf, Qrequest $qreq) {
        // Look up the account information
        // to determine if the user is registered
        if (isset($qreq->email)) {
            $qreq->email = simplify_whitespace($qreq->email);
        }
        if (!isset($qreq->email) || $qreq->email === "") {
            return ["ok" => false, "email" => true, "noemail" => true];
        }
        if (strpos($qreq->email, "@") === false
            && strpos($qreq->email, "%40") !== false) {
            foreach ($qreq->keys() as $k) {
                $qreq[$k] = rawurldecode($qreq[$k]);
            }
        }
        return ["ok" => true, "user" => $conf->user_by_email($qreq->email)
            ?? Contact::make_keyed($conf, $qreq->subset_as_array(
                "firstName", "first", "lastName", "last", "name", "email", "affiliation"
            ))];
    }

    /** @return array{ok:true,user:Contact}|array{ok:false} */
    static function login_info(Conf $conf, Qrequest $qreq) {
        assert(!$conf->external_login());
        assert($qreq->valid_post());

        $info = self::user_lookup($conf, $qreq);
        if ($info["ok"]) {
            $info = $info["user"]->check_password_info(trim((string) $qreq->password));
        }
        return $info;
    }

    /** @return array{ok:true,user:Contact}|array{ok:false,disabled?:true,email?:true} */
    static function external_login_info(Conf $conf, Qrequest $qreq) {
        assert($conf->external_login());

        $info = self::user_lookup($conf, $qreq);
        if (!$info["ok"]) {
            return $info;
        }
        $user = $info["user"];

        // do LDAP login before validation, since we might create an account
        if ($conf->opt("ldapLogin")) {
            $info = LdapLogin::ldap_login_info($conf, $qreq);
            if (!$info["ok"]) {
                return $info;
            }
        }

        // auto-create account if external login
        if (!$user->contactId
            && !$user->store(Contact::SAVE_ANY_EMAIL)) {
            return ["ok" => false, "internal" => true, "email" => true];
        }

        // if user disabled, then fail
        if ($user->is_disabled()
            || (($cdbuser = $user->cdb_user()) && $cdbuser->is_disabled())) {
            return ["ok" => false, "disabled" => true, "email" => true];
        } else {
            return ["ok" => true, "user" => $user];
        }
    }

    /** @param array{ok:true,user:Contact} $info
     * @return array{ok:true,user:Contact,redirect:string,firstuser?:true} */
    static function login_complete($info, Qrequest $qreq) {
        assert($info["ok"] && $info["user"]);
        $luser = $info["user"];

        // mark activity
        $xuser = $luser->contactId ? $luser : $luser->cdb_user();
        $xuser->mark_login();

        // store authentication
        ensure_session(ENSURE_SESSION_REGENERATE_ID);
        self::change_session_users([$xuser->email => 1]);
        $_SESSION["testsession"] = true;

        // activate
        $user = $xuser->activate($qreq);
        $user->save_session("password_reset", null);

        $nav = Navigation::get();
        $url = $nav->server . $nav->base_path;
        if (isset($_SESSION["us"])) {
            $url .= "u/" . Contact::session_user_index($user->email) . "/";
        }
        $url .= "?postlogin=1";
        if ($qreq->redirect !== null && $qreq->redirect !== "1") {
            $url .= "&redirect=" . urlencode($qreq->redirect);
        }

        $info["user"] = $user;
        $info["redirect"] = $url;
        if (self::check_setup_phase($user)) {
            $info["firstuser"] = true;
        }
        return $info;
    }

    static function change_session_users($uinstr) {
        $us = Contact::session_users();
        foreach ($uinstr as $e => $delta) {
            for ($i = 0; $i !== count($us); ++$i) {
                if (strcasecmp($us[$i], $e) === 0)
                    break;
            }
            if ($delta < 0 && $i !== count($us)) {
                array_splice($us, $i, 1);
            } else if ($delta > 0 && $i === count($us)) {
                $us[] = $e;
            }
        }
        if (count($us) > 1) {
            $_SESSION["us"] = $us;
        } else {
            unset($_SESSION["us"]);
        }
        if (empty($us)) {
            unset($_SESSION["u"]);
        } else if (!isset($_SESSION["u"]) || $us[0] !== $_SESSION["u"]) {
            $_SESSION["u"] = $us[0];
        }
    }

    /** @return bool */
    static private function check_setup_phase(Contact $user) {
        if ($user->conf->setting("setupPhase")) {
            $user->ensure_account_here()->save_roles(Contact::ROLE_ADMIN, null);
            $user->conf->save_setting("setupPhase", null);
            return true;
        } else {
            return false;
        }
    }

    static function check_postlogin(Contact $user, Qrequest $qreq) {
        // Check for the cookie
        if (!isset($_SESSION["testsession"]) || !$_SESSION["testsession"]) {
            $user->conf->feedback_msg([
                MessageItem::error("<0>Cookies required"),
                MessageItem::inform("<0>You appear to have disabled cookies in your browser. This site requires cookies to function.")
            ]);
            return;
        }
        unset($_SESSION["testsession"]);

        // Go places
        if (isset($qreq->redirect)) {
            $where = $qreq->redirect;
        } else if (isset($_SESSION["login_bounce"])
                   && $_SESSION["login_bounce"][0] == $user->conf->dbname) {
            $where = $_SESSION["login_bounce"][1];
        } else {
            $user->save_session("freshlogin", true);
            $where = $user->conf->hoturl_raw("index");
        }
        $user->conf->redirect($where);
        exit;
    }


    /** @return array{ok:true,user:Contact}|array{ok:false,email?:true} */
    static function new_account_info(Conf $conf, Qrequest $qreq) {
        assert($conf->allow_user_self_register());
        assert($qreq->valid_post());

        $info = self::user_lookup($conf, $qreq);
        if (!$info["ok"]) {
            return $info;
        }
        $user = $info["user"];

        $cdbu = $user->cdb_user();
        if ($cdbu && !$cdbu->password_unset()) {
            return ["ok" => false, "email" => true, "userexists" => true, "contactdb" => true];
        } else if (!$user->password_unset()) {
            return ["ok" => false, "email" => true, "userexists" => true];
        } else if (!validate_email($qreq->email)) {
            return ["ok" => false, "email" => true, "invalidemail" => true];
        } else if (!$user->has_account_here() && !$user->store()) {
            return ["ok" => false, "email" => true, "internal" => true];
        } else {
            $conf->invalidate_user($user);
            $info = self::forgot_password_info($conf, $qreq, true);
            if ($info["ok"] && $info["mailtemplate"] === "@resetpassword") {
                $info["mailtemplate"] = "@newaccount.selfregister";
                if (self::check_setup_phase($info["user"])) {
                    $info["firstuser"] = true;
                }
            }
            return $info;
        }
    }


    /** @return array{ok:true,user:Contact,mailtemplate:string}|array{ok:false} */
    static function forgot_password_info(Conf $conf, Qrequest $qreq, $create) {
        if ($conf->external_login()) {
            return ["ok" => false, "email" => true, "noreset" => true];
        }

        $info = self::user_lookup($conf, $qreq);
        if (!$info["ok"]) {
            return $info;
        }
        $user = $info["user"];
        $cdbu = $user->cdb_user();

        // check for nonexistent users (placeholders count as nonexistent)
        if ((!$user->has_account_here() || $user->is_placeholder())
            && (!$cdbu || $cdbu->is_placeholder())
            && !$create) {
            return ["ok" => false, "email" => true, "unset" => true];
        }

        // check for users that cannot reset their password
        if (!$user->can_reset_password()) {
            return ["ok" => false, "email" => true, "nologin" => true];
        }

        // disabled users get mail saying they're disabled
        if ($user->is_disabled()
            || (!$user->contactId && !$conf->allow_user_self_register())
            || ($cdbu && $cdbu->is_disabled())) {
            $template = "@resetdisabled";
        } else {
            $template = "@resetpassword";
        }
        return ["ok" => true, "user" => $user, "mailtemplate" => $template];
    }


    static function logout(Contact $user, $explicit) {
        if (isset($_SESSION)) {
            $_SESSION = [];
            session_commit();
        }
        if ($explicit && $user->conf->opt("httpAuthLogin")) {
            ensure_session(ENSURE_SESSION_REGENERATE_ID);
            $_SESSION["reauth"] = true;
        } else if ($explicit) {
            kill_session();
        }
        $user = Contact::make($user->conf);
        return $user->activate(null);
    }


    static function login_error(Conf $conf, Qrequest $qreq, $info) {
        $email = trim($qreq->email);
        if (self::DEBUG) {
            error_log("{$conf->dbname} login failure: $email " . json_encode($info) . " " . json_encode($qreq));
        }
        $xemail = $email === "" ? null : $email;
        if (isset($info["ldap"]) && isset($info["detail_html"])) {
            $e = $info["detail_html"];
        } else if (isset($info["noemail"])) {
            $e = $conf->opt("ldapLogin") ? "Enter your username." : "Enter your email address.";
        } else if (isset($info["invalidemail"])) {
            $e = "Enter a valid email address.";
        } else if (isset($info["nocreate"])) {
            $e = "Users can’t self-register for this site.";
        } else if (isset($info["noreset"])) {
            $e = "Password reset links aren’t used for this site. Contact your system administrator if you’ve forgotten your password.";
        } else if (isset($info["nologin"])) {
            $e = "User %2[email]\$H cannot sign in to the site.";
        } else if (isset($info["userexists"])) {
            $e = "Incorrect password.";
        } else if (isset($info["unset"])) {
            if ($conf->allow_user_self_register()) {
                $e = "User %2[email]\$H does not have an account. Check the email address or <a href=\"%2[newaccount]\$H\">create that account</a>.";
            } else {
                $e = "User %2[email]\$H does not have an account. Check the email address.";
            }
        } else if (isset($info["disabled"])) {
            $e = "Your account on this site is disabled. Contact the site administrator for more information.";
        } else if (isset($info["reset"])) {
            $e = "Your password has expired. Use <a href=\"%2[forgotpassword]\$H\">“Forgot your password?”</a> to reset it.";
        } else if (isset($info["nopw"])) {
            $e = "Enter your password.";
        } else if (isset($info["nopost"])) {
            $e = "Automatic login links have been disabled for security. Use this form to sign in.";
        } else if (isset($info["internal"])) {
            $e = "Internal error.";
        } else {
            $e = "Incorrect password.";
        }
        $e = $conf->_id("signin_error", $e, $info, [
            "email" => $email,
            "signin" => $conf->hoturl_raw("signin", ["email" => $xemail]),
            "forgotpassword" => $conf->hoturl_raw("forgotpassword", ["email" => $xemail]),
            "newaccount" => $conf->hoturl_raw("newaccount", ["email" => $xemail])
        ]);
        Ht::error_at(isset($info["email"]) ? "email" : "password", $e);
        if (isset($info["password"])) {
            Ht::error_at("password");
        }
        return false;
    }
}
