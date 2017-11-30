<?php
// profile.php -- HotCRP profile management page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

// check for change-email capabilities
function change_email_by_capability() {
    global $Conf, $Me;
    $capmgr = $Conf->capability_manager();
    $capdata = $capmgr->check($_REQUEST["changeemail"]);
    if (!$capdata || $capdata->capabilityType != CAPTYPE_CHANGEEMAIL
        || !($capdata->data = json_decode($capdata->data))
        || !@$capdata->data->uemail)
        error_go(false, "That email change code has expired, or you didn’t enter it correctly.");
    $Acct = Contact::find_by_id($capdata->contactId);
    if (!$Acct)
        error_go(false, "No such account.");

    $email = $capdata->data->uemail;
    if (Contact::id_by_email($email))
        error_go(false, "Email address " . htmlspecialchars($email) . " is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");

    $Acct->change_email($email);
    $capmgr->delete($capdata);

    $Conf->confirmMsg("Your email address has been changed.");
    if (!$Me->has_database_account() || $Me->contactId == $Acct->contactId)
        $Me = $Acct->activate();
}
if (isset($_REQUEST["changeemail"]))
    change_email_by_capability();

if (!$Me->has_email())
    $Me->escape();
$newProfile = false;
$useRequest = false;
$UserStatus = new UserStatus;

if (!isset($_REQUEST["u"]) && isset($_REQUEST["user"]))
    $_REQUEST["u"] = $_REQUEST["user"];
if (!isset($_REQUEST["u"]) && isset($_REQUEST["contact"]))
    $_REQUEST["u"] = $_REQUEST["contact"];
if (!isset($_REQUEST["u"])
    && preg_match(',\A/(?:new|[^\s/]+)\z,i', Navigation::path()))
    $_REQUEST["u"] = substr(Navigation::path(), 1);
if ($Me->privChair && @$_REQUEST["new"])
    $_REQUEST["u"] = "new";


// Load user.
$Acct = $Me;
if ($Me->privChair && @$_REQUEST["u"]) {
    if ($_REQUEST["u"] === "new") {
        $Acct = new Contact;
        $newProfile = true;
    } else if (($id = cvtint($_REQUEST["u"])) > 0)
        $Acct = Contact::find_by_id($id);
    else
        $Acct = Contact::find_by_email($_REQUEST["u"]);
}

// Redirect if requested user isn't loaded user.
if (!$Acct
    || (isset($_REQUEST["u"])
        && $_REQUEST["u"] !== (string) $Acct->contactId
        && strcasecmp($_REQUEST["u"], $Acct->email)
        && ($Acct->contactId || $_REQUEST["u"] !== "new"))
    || (isset($_REQUEST["profile_contactid"])
        && $_REQUEST["profile_contactid"] !== (string) $Acct->contactId)) {
    if (!$Acct)
        $Conf->errorMsg("Invalid user.");
    else if (isset($_REQUEST["register"]) || isset($_REQUEST["bulkregister"]))
        $Conf->errorMsg("You’re logged in as a different user now, so your changes were ignored.");
    unset($_REQUEST["u"], $_REQUEST["register"], $_REQUEST["bulkregister"]);
    redirectSelf();
}

if (($Acct->contactId != $Me->contactId || !$Me->has_database_account())
    && $Acct->has_email()
    && !$Acct->firstName && !$Acct->lastName && !$Acct->affiliation
    && !isset($_REQUEST["post"])) {
    $result = $Conf->qe("select Paper.paperId, authorInformation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$Acct->contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")");
    while (($prow = edb_orow($result))) {
        cleanAuthor($prow);
        foreach ($prow->authorTable as $au)
            if (strcasecmp($au[2], $Acct->email) == 0
                && ($au[0] || $au[1] || $au[3])) {
                if (!$Acct->firstName && $au[0])
                    $Acct->firstName = $au[0];
                if (!$Acct->lastName && $au[1])
                    $Acct->lastName = $au[1];
                if (!$Acct->affiliation && $au[3])
                    $Acct->affiliation = $au[3];
                ++$UserStatus->nerrors;
            }
    }
}


function pc_request_as_json($cj) {
    global $Conf, $Me, $Acct, $newProfile;
    if ($Me->privChair && isset($_REQUEST["pctype"])) {
        $cj->roles = (object) array();
        if (@$_REQUEST["pctype"] === "chair")
            $cj->roles->chair = $cj->roles->pc = true;
        if (@$_REQUEST["pctype"] === "pc")
            $cj->roles->pc = true;
        if (@$_REQUEST["ass"])
            $cj->roles->sysadmin = true;
    }
    $cj->follow = (object) array();
    if (@$_REQUEST["watchcomment"])
        $cj->follow->reviews = true;
    if (($Me->privChair || $Acct->isPC) && @$_REQUEST["watchcommentall"])
        $cj->follow->allreviews = true;
    if ($Me->privChair && @$_REQUEST["watchfinalall"])
        $cj->follow->allfinal = true;
    if ($Me->privChair && isset($_REQUEST["contactTags"]))
        $cj->tags = explode(" ", simplify_whitespace($_REQUEST["contactTags"]));
    if ($Me->privChair ? @$cj->roles->pc : $Me->isPC) {
        $topics = (object) array();
        foreach ($Conf->topic_map() as $id => $t)
            if (isset($_REQUEST["ti$id"]) && is_numeric($_REQUEST["ti$id"]))
                $topics->$id = (int) $_REQUEST["ti$id"];
        if (count(get_object_vars($topics)))
            $cj->topics = (object) $topics;
    }
    return $cj;
}

function web_request_as_json($cj) {
    global $Conf, $Me, $Acct, $newProfile, $UserStatus;

    if ($newProfile || !$Acct->has_database_account())
        $cj->id = "new";
    else
        $cj->id = $Acct->contactId;

    if (!Contact::external_login())
        $cj->email = trim(defval($_REQUEST, "uemail", ""));
    else if ($newProfile)
        $cj->email = trim(defval($_REQUEST, "newUsername", ""));
    else
        $cj->email = $Acct->email;

    foreach (array("firstName", "lastName", "preferredEmail", "affiliation",
                   "collaborators", "addressLine1", "addressLine2",
                   "city", "state", "zipCode", "country", "voicePhoneNumber") as $k)
        if (isset($_REQUEST[$k]))
            $cj->$k = $_REQUEST[$k];

    if (!Contact::external_login() && !$newProfile
        && $Me->can_change_password($Acct)) {
        if (@$_REQUEST["whichpassword"] === "t" && @$_REQUEST["upasswordt"])
            $pw = $pw2 = @trim($_REQUEST["upasswordt"]);
        else {
            $pw = @trim($_REQUEST["upassword"]);
            $pw2 = @trim($_REQUEST["upassword2"]);
        }
        if ($pw === "" && $pw2 === "")
            /* do nothing */;
        else if ($pw !== $pw2)
            $UserStatus->set_error("password", "Those passwords do not match.");
        else if (!Contact::valid_password($pw))
            $UserStatus->set_error("password", "Invalid new password.");
        else if (!$Acct || $Me->can_change_password(null)) {
            $cj->old_password = null;
            $cj->new_password = $pw;
        } else {
            $cj->old_password = @trim($_REQUEST["oldpassword"]);
            if ($Acct->check_password($cj->old_password))
                $cj->new_password = $pw;
            else
                $UserStatus->set_error("password", "Incorrect current password. New password ignored.");
        }
    }
}

function save_user($cj, $user_status) {
    global $Conf, $Acct, $Me, $Opt, $OK, $newProfile;

    // check for missing fields
    UserStatus::normalize_name($cj);
    if ($newProfile && !isset($cj->email)) {
        $user_status->set_error($field, "Email address required.");
        return false;
    }

    // check email
    if ($newProfile || $cj->email != $Acct->email) {
        if (Contact::id_by_email($cj->email)) {
            $msg = htmlspecialchars($cj->email) . " has already registered an account.";
            if ($Me->privChair)
                $msg = str_replace("an account", "<a href=\"" . hoturl("profile", "u=" . urlencode($cj->email)) . "\">an account</a>", $msg);
            if (!$newProfile)
                $msg .= " You may want to <a href='" . hoturl("mergeaccounts") . "'>merge these accounts</a>.";
            return $user_status->set_error("email", $msg);
        } else if (Contact::external_login()) {
            if ($cj->email === "")
                return $user_status->set_error("email", "Not a valid username.");
        } else if ($cj->email === "")
            return $user_status->set_error("email", "You must supply an email address.");
        else if (!validate_email($cj->email))
            return $user_status->set_error("email", "“" . htmlspecialchars($cj->email) . "” is not a valid email address.");
        if (!$newProfile && !$Me->privChair) {
            $old_preferredEmail = $Acct->preferredEmail;
            $Acct->preferredEmail = $cj->email;
            $capmgr = $Conf->capability_manager();
            $rest = array("capability" => $capmgr->create(CAPTYPE_CHANGEEMAIL, array("user" => $Acct, "timeExpires" => cheng_time() + 259200, "data" => json_encode(array("uemail" => $cj->email)))));
            $mailer = new HotCRPMailer($Acct, null, $rest);
            $prep = $mailer->make_preparation("@changeemail", $rest);
            if ($prep->sendable) {
                Mailer::send_preparation($prep);
                $Conf->warnMsg("Mail has been sent to " . htmlspecialchars($cj->email) . ". Use the link it contains to confirm your email change request.");
            } else
                $Conf->errorMsg("Mail cannot be sent to " . htmlspecialchars($cj->email) . " at this time. Your email address was unchanged.");
            // Save changes *except* for new email, by restoring old email.
            $cj->email = $Acct->email;
            $Acct->preferredEmail = $old_preferredEmail;
        }
    }

    // save account
    return $user_status->save($cj, $newProfile ? null : $Acct, $Me);
}


function parseBulkFile($text, $filename) {
    global $Conf;
    $text = cleannl($text);
    if (!is_valid_utf8($text))
        $text = windows_1252_to_utf8($text);
    $filename = $filename ? "$filename:" : "line ";
    $success = array();

    if (!preg_match('/\A[^\r\n]*(?:,|\A)(?:user|email)(?:[,\r\n]|\z)/', $text)
        && !preg_match('/\A[^\r\n]*,[^\r\n]*,/', $text)) {
        $tarr = CsvParser::split_lines($text);
        foreach ($tarr as &$t) {
            if (($t = trim($t)) && $t[0] !== "#" && $t[0] !== "%")
                $t = CsvGenerator::quote($t);
            $t .= "\n";
        }
        unset($t);
        $text = join("", $tarr);
    }

    $csv = new CsvParser($text);
    $csv->set_comment_chars("#%");
    $line = $csv->next();
    if ($line && (array_search("email", $line) !== false
                  || array_search("user", $line) !== false))
        $csv->set_header($line);
    else {
        $csv->set_header(array("user"));
        $csv->unshift($line);
    }

    $cj_template = (object) array();
    $topic_revmap = array();
    foreach ($Conf->topic_map() as $id => $name)
        $topic_revmap[strtolower($name)] = $id;
    $unknown_topics = array();
    $errors = array();

    while (($line = $csv->next()) !== false) {
        $cj = clone $cj_template;
        foreach ($line as $k => $v)
            $cj->$k = $v;
        foreach (array("firstname" => "firstName", "first" => "firstName",
                       "lastname" => "lastName", "last" => "lastName",
                       "fullname" => "name", "fullName" => "name",
                       "voice" => "voicePhoneNumber", "phone" => "voicePhoneNumber",
                       "address1" => "addressLine1", "province" => "state", "region" => "state",
                       "address2" => "addressLine2", "postalcode" => "zipCode",
                       "zip" => "zipCode", "tags" => "contactTags") as $k => $x)
            if (isset($cj->$k) && !isset($cj->$x))
                $cj->$x = $cj->$k;
        // thou shalt not set passwords by bulk update
        unset($cj->password, $cj->password_plaintext, $cj->new_password);
        if (isset($cj->name) && !isset($cj->firstName) && !isset($cj->lastName))
            list($cj->firstName, $cj->lastName) = Text::split_name($cj->name);
        if (count($topic_revmap)) {
            foreach (array_keys($line) as $k)
                if (preg_match('/^topic:\s*(.*?)\s*$/i', $k, $m)) {
                    if (($ti = @$topic_revmap[strtolower($m[1])]) !== null) {
                        $x = $line[$k];
                        if (strtolower($x) === "low")
                            $x = -2;
                        else if (strtolower($x) === "high")
                            $x = 4;
                        else if (!is_numeric($x))
                            $x = 0;
                        if (!@$cj->topics)
                            $cj->topics = (object) array();
                        $cj->topics->$ti = $x;
                    } else
                        $unknown_topics[$m[1]] = true;
                }
        }
        $cj->id = "new";

        $ustatus = new UserStatus(array("send_email" => true));
        if (($saved_user = save_user($cj, $ustatus)))
            $success[] = "<a href=\"" . hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">"
                . Text::user_html_nolink($saved_user) . "</a>";
        else
            foreach ($ustatus->error_messages() as $e)
                $errors[] = "<span class='lineno'>" . $filename . $csv->lineno() . ":</span> " . $e;
    }

    if (count($unknown_topics))
        $errors[] = "There were unrecognized topics (" . htmlspecialchars(commajoin($unknown_topics)) . ").";
    if (count($success) == 1)
        $successMsg = "Created account " . $success[0] . ".";
    else if (count($success))
        $successMsg = "Created " . plural($success, "account") . ": " . commajoin($success) . ".";
    if (count($errors))
        $errorMsg = "were errors while parsing the new accounts. <div class='parseerr'><p>" . join("</p>\n<p>", $errors) . "</p></div>";
    if (count($success) && count($errors))
        $Conf->confirmMsg($successMsg . "<br />However, there $errorMsg");
    else if (count($success))
        $Conf->confirmMsg($successMsg);
    else if (count($errors))
        $Conf->errorMsg("There $errorMsg");
    else
        $Conf->warnMsg("Nothing to do.");
    return count($errors) == 0;
}

if (!check_post())
    /* do nothing */;
else if (isset($_REQUEST["bulkregister"]) && $newProfile
         && fileUploaded($_FILES["bulk"])) {
    if (($text = file_get_contents($_FILES["bulk"]["tmp_name"])) === false)
        $Conf->errorMsg("Internal error: cannot read file.");
    else
        parseBulkFile($text, $_FILES["bulk"]["name"]);
    $Acct = new Contact;
    $_REQUEST["bulkentry"] = "";
    redirectSelf(array("anchor" => "bulk"));
} else if (isset($_REQUEST["bulkregister"]) && $newProfile) {
    $success = true;
    if (@$_REQUEST["bulkentry"]
        && $_REQUEST["bulkentry"] !== "Enter users one per line")
        $success = parseBulkFile($_REQUEST["bulkentry"], "");
    $Acct = new Contact;
    if (!$success)
        $Conf->save_session("profile_bulkentry", array($Now, $_REQUEST["bulkentry"]));
    redirectSelf(array("anchor" => "bulk"));
} else if (isset($_REQUEST["register"])) {
    $cj = (object) array();
    web_request_as_json($cj);
    pc_request_as_json($cj);
    $saved_user = save_user($cj, $UserStatus);
    if ($UserStatus->nerrors)
        $Conf->errorMsg("<div>" . join("</div><div style='margin-top:0.5em'>", $UserStatus->error_messages()) . "</div>");
    else {
        if ($newProfile)
            $Conf->confirmMsg("Created an account for <a href=\"" . hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">" . Text::user_html_nolink($saved_user) . "</a>. A password has been emailed to that address. You may now create another account.");
        else {
            $Conf->confirmMsg("Account profile updated.");
            if ($Acct->contactId == $Me->contactId)
                $Me->update_trueuser(true);
            else
                $_REQUEST["u"] = $Acct->email;
        }
        if (isset($_REQUEST["redirect"]))
            go(hoturl("index"));
        else {
            if ($newProfile)
                $Conf->save_session("profile_redirect", $cj);
            redirectSelf();
        }
    }
} else if (isset($_REQUEST["merge"]) && !$newProfile
           && $Acct->contactId == $Me->contactId)
    go(hoturl("mergeaccounts"));
else if (isset($_REQUEST["clickthrough"]))
    UserActions::save_clickthrough($Acct);

function databaseTracks($who) {
    global $Conf;
    $tracks = (object) array("soleAuthor" => array(),
                             "author" => array(),
                             "review" => array(),
                             "comment" => array());

    // find authored papers
    $result = $Conf->qe("select Paper.paperId, count(pc.contactId)
        from Paper
        join PaperConflict c on (c.paperId=Paper.paperId and c.contactId=$who and c.conflictType>=" . CONFLICT_AUTHOR . ")
        join PaperConflict pc on (pc.paperId=Paper.paperId and pc.conflictType>=" . CONFLICT_AUTHOR . ")
        group by Paper.paperId order by Paper.paperId");
    while (($row = edb_row($result))) {
        if ($row[1] == 1)
            $tracks->soleAuthor[] = $row[0];
        $tracks->author[] = $row[0];
    }

    // find reviews
    $result = $Conf->qe("select paperId from PaperReview
        where PaperReview.contactId=$who
        group by paperId order by paperId");
    while (($row = edb_row($result)))
        $tracks->review[] = $row[0];

    // find comments
    $result = $Conf->qe("select paperId from PaperComment
        where PaperComment.contactId=$who
        group by paperId order by paperId");
    while (($row = edb_row($result)))
        $tracks->comment[] = $row[0];

    return $tracks;
}

function textArrayPapers($pids) {
    return commajoin(preg_replace('/(\d+)/', "<a href='" . hoturl("paper", "p=\$1&amp;ls=" . join("+", $pids)) . "'>\$1</a>", $pids));
}

if (isset($_REQUEST["delete"]) && $OK && check_post()) {
    if (!$Me->privChair)
        $Conf->errorMsg("Only administrators can delete users.");
    else if ($Acct->contactId == $Me->contactId)
        $Conf->errorMsg("You aren’t allowed to delete yourself.");
    else if ($Acct->has_database_account()) {
        $tracks = databaseTracks($Acct->contactId);
        if (count($tracks->soleAuthor))
            $Conf->errorMsg("This user can’t be deleted since they are sole contact for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ".  You will be able to delete the user after deleting those papers or adding additional paper contacts.");
        else {
            foreach (array("ContactInfo",
                           "PaperComment", "PaperConflict", "PaperReview",
                           "PaperReviewPreference", "PaperReviewRefused",
                           "PaperWatch", "ReviewRating", "TopicInterest")
                     as $table)
                $Conf->qe("delete from $table where contactId=$Acct->contactId");
            // delete twiddle tags
            $assigner = new AssignmentSet($Me, true);
            $assigner->parse("paper,tag\nall,{$Acct->contactId}~all#clear\n");
            $assigner->execute();
            // clear caches
            if ($Acct->isPC || $Acct->privChair)
                $Conf->invalidateCaches(array("pc" => 1));
            // done
            $Conf->confirmMsg("Permanently deleted user " . htmlspecialchars($Acct->email) . ".");
            $Me->log_activity("Permanently deleted user " . htmlspecialchars($Acct->email) . " ($Acct->contactId)");
            go(hoturl("users", "t=all"));
        }
    }
}

function value($key, $value) {
    global $useRequest;
    if ($useRequest && isset($_REQUEST[$key]))
        return htmlspecialchars($_REQUEST[$key]);
    else
        return $value ? htmlspecialchars($value) : "";
}

function contact_value($key, $field = null) {
    global $Acct, $useRequest;
    if ($useRequest && isset($_REQUEST[$key]))
        return htmlspecialchars($_REQUEST[$key]);
    else if ($field == "password") {
        $v = $Acct->plaintext_password();
        return htmlspecialchars($v ? : "");
    } else if ($key == "contactTags")
        return htmlspecialchars($Acct->all_contact_tags());
    else if ($field !== false) {
        $v = $field ? $Acct->$field : $Acct->$key;
        return htmlspecialchars($v === null ? "" : $v);
    } else
        return "";
}

function fcclass($field = false) {
    global $UserStatus;
    if ($field && $UserStatus->has_error($field))
        return "f-c error";
    else
        return "f-c";
}

function feclass($field = false) {
    global $UserStatus;
    if ($field && $UserStatus->has_error($field))
        return "f-e error";
    else
        return "f-e";
}

function echofield($type, $classname, $captiontext, $entrytext) {
    if ($type <= 1)
        echo '<div class="f-i">';
    if ($type >= 1)
        echo '<div class="f-ix">';
    echo '<div class="', fcclass($classname), '">', $captiontext, "</div>",
        '<div class="', feclass($classname), '">', $entrytext, "</div></div>\n";
    if ($type > 2)
        echo '<hr class="c" />', "</div>\n";
}

function textinput($name, $value, $size, $id = false, $password = false) {
    return '<input type="' . ($password ? "password" : "text")
        . '" name="' . $name . '" ' . ($id ? "id=\"$id\" " : "")
        . 'size="' . $size . '" value="' . $value . '" />';
}

function create_modes($hlbulk) {
    echo '<div class="psmode">',
        '<div class="', ($hlbulk ? "papmode" : "papmodex"), '">',
        Ht::js_link("Create account", "fold('bulk',null,9)"),
        '</div><div class="', ($hlbulk ? "papmodex" : "papmode"), '">',
        Ht::js_link("Bulk upload", "fold('bulk',null,9)"),
        '</div></div><hr class="c" style="margin-bottom:24px" />', "\n";
}


if ($newProfile)
    $Conf->header("Create account", "account", actionBar("account"));
else
    $Conf->header($Me->email == $Acct->email ? "Profile" : "Account profile", "account", actionBar("account", $Acct));
$useRequest = (!$Acct->has_database_account() && isset($_REQUEST["watchcomment"]))
    || $UserStatus->nerrors;

if (!$UserStatus->nerrors && @$Conf->session("freshlogin") === "redirect") {
    $Conf->save_session("freshlogin", null);
    $ispc = $Acct->is_pclike();
    $msgs = array();
    $amsg = "";
    if (!$Me->firstName && !$Me->lastName)
        $msgs[] = "enter your name" . ($Me->affiliation ? "" : " and affiliation");
    else if (!$Me->affiliation)
        $msgs[] = "enter your affiliation";
    if ($ispc && !$Me->collaborators)
        $msgs[] = "list your recent collaborators";
    if ($ispc || $Conf->setting("acct_addr") || !count($msgs))
        $msgs[] = "update your " . (count($msgs) ? "other " : "") . "contact information";
    if (!$Me->affiliation || ($ispc && !$Me->collaborators)) {
        $amsg .= "  We use your ";
        if (!$Me->affiliation)
            $amsg .= "affiliation ";
        if ($ispc && !$Me->collaborators)
            $amsg .= ($Me->affiliation ? "" : "and ") . "recent collaborators ";
        $amsg .= "to detect paper conflicts; enter “None”";
        if (!$Me->affiliation)
            $amsg .= " or “Unaffiliated”";
        $amsg .= " if you have none.";
    }
    if ($ispc) {
        $result = $Conf->q("select count(ta.topicId), count(ti.topicId) from TopicArea ta left join TopicInterest ti on (ti.contactId=$Me->contactId and ti.topicId=ta.topicId)");
        if (($row = edb_row($result)) && $row[0] && !$row[1]) {
            $msgs[] = "tell us your topic interests";
            $amsg .= "  We use your topic interests to assign you papers you might like.";
        }
    }
    $Conf->infoMsg("Please take a moment to " . commajoin($msgs) . "." . $amsg);
}


if ($useRequest) {
    $formcj = (object) array();
    pc_request_as_json($formcj);
} else if (($formcj = $Conf->session("profile_redirect")))
    $Conf->save_session("profile_redirect", null);
else
    $formcj = $UserStatus->user_to_json($Acct);
$pcrole = @($formcj->roles->chair) ? "chair" : (@($formcj->roles->pc) ? "pc" : "no");
if (!$useRequest && $Me->privChair && $newProfile
    && (@$_REQUEST["role"] == "chair" || @$_REQUEST["role"] == "pc"))
    $pcrole = $_REQUEST["role"];


$form_params = array();
if ($newProfile)
    $form_params[] = "u=new";
else if ($Me->contactId != $Acct->contactId)
    $form_params[] = "u=" . urlencode($Acct->email);
if (isset($_REQUEST["ls"]))
    $form_params[] = "ls=" . urlencode($_REQUEST["ls"]);
if ($newProfile)
    echo '<div id="foldbulk" class="fold9' . (@$_REQUEST["bulkregister"] ? "o" : "c") . '"><div class="fn9">';

echo Ht::form(hoturl_post("profile", join("&amp;", $form_params)),
              array("id" => "accountform", "autocomplete" => "off")),
    '<div class="profiletext aahc', ($UserStatus->nerrors ? " alert" : "") , "\">\n",
    // Don't want chrome to autofill the password changer.
    // But chrome defaults to autofilling the password changer
    // unless we supply an earlier password input.
    Ht::password("chromefooler", "", array("style" => "display:none")),
    Ht::hidden("profile_contactid", $Acct->contactId);
if (isset($_REQUEST["redirect"]))
    echo Ht::hidden("redirect", $_REQUEST["redirect"]);
if ($Me->privChair)
    echo Ht::hidden("whichpassword", "");

echo '<div id="foldaccount" class="form foldc ',
    ($pcrole == "no" ? "fold1c " : "fold1o "),
    (fileUploaded($_FILES["bulk"]) ? "fold2o" : "fold2c"), '">';

if ($newProfile)
    create_modes(false);

echo '<div class="f-contain">', "\n\n";
if (!isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"]))
    echofield(0, "uemail", "Email", textinput("uemail", contact_value("uemail", "email"), 52, "account_d"));
else if (!$newProfile) {
    echofield(0, "uemail", "Username", contact_value("uemail", "email"));
    echofield(0, "preferredEmail", "Email", textinput("preferredEmail", contact_value("preferredEmail"), 52, "account_d"));
} else {
    echofield(0, "uemail", "Username", textinput("newUsername", contact_value("newUsername", false), 52, "account_d"));
    echofield(0, "preferredEmail", "Email", textinput("preferredEmail", contact_value("preferredEmail"), 52));
}

echofield(1, "firstName", "First&nbsp;name", textinput("firstName", contact_value("firstName"), 24));
echofield(3, "lastName", "Last&nbsp;name", textinput("lastName", contact_value("lastName"), 24));
echofield(0, "affiliation", "Affiliation", textinput("affiliation", contact_value("affiliation"), 52));


$data = $Acct->data();
$any_address = $data && @($data->address || $data->city || $data->state || $data->zip || $data->country);
if ($Conf->setting("acct_addr") || $any_address || $Acct->voicePhoneNumber) {
    echo "<div style='margin-top:20px'></div>\n";
    echofield(0, false, "Address line 1", textinput("addressLine1", value("addressLine1", @$data->address ? @$data->address[0] : null), 52));
    echofield(0, false, "Address line 2", textinput("addressLine2", value("addressLine2", @$data->address ? @$data->address[1] : null), 52));
    echofield(0, false, "City", textinput("city", value("city", @$data->city), 52));
    echofield(1, false, "State/Province/Region", textinput("state", value("state", @$data->state), 24));
    echofield(3, false, "ZIP/Postal code", textinput("zipCode", value("zipCode", @$data->zip), 12));
    echofield(0, false, "Country", Countries::selector("country", (isset($_REQUEST["country"]) ? $_REQUEST["country"] : @$data->country)));
    echofield(0, false, "Phone <span class='f-cx'>(optional)</span>", textinput("voicePhoneNumber", contact_value("voicePhoneNumber"), 24));
}


if (!$newProfile && !isset($Opt["ldapLogin"]) && !isset($Opt["httpAuthLogin"])
    && $Me->can_change_password($Acct)) {
    echo '<div id="foldpassword" class="',
        ($UserStatus->has_error("password") ? "fold3o" : "fold3c"),
        '" style="margin-top:20px">';
    // Hit a button to change your password
    echo Ht::js_button("Change password", "fold('password',null,3)", array("class" => "fn3"));
    // Display the following after the button is clicked
    echo '<div class="fx3">';
    if (!$Me->can_change_password(null)) {
        echo '<div class="f-h">Enter your current password as well as your desired new password.</div>';
        echo '<div class="f-i"><div class="', fcclass("password"), '">Current password</div>',
            '<div class="', feclass("password"), '">', Ht::password("oldpassword", "", array("size" => 24)), '</div>',
            '</div>';
    }
    if (@$Opt["contactdb_dsn"] && @$Opt["contactdb_loginFormHeading"])
        echo $Opt["contactdb_loginFormHeading"];
    echo '<div class="f-i"><div class="f-ix">
  <div class="', fcclass("password"), '">New password</div>
  <div class="', feclass("password"), '">', Ht::password("upassword", "", array("size" => 24, "class" => "fn"));
    if ($Acct->plaintext_password() && $Me->privChair)
        echo Ht::entry("upasswordt", contact_value("upasswordt", "password"), array("size" => 24, "class" => "fx"));
    echo '</div>
</div><div class="fn f-ix">
  <div class="', fcclass("password"), '">Repeat new password</div>
  <div class="', feclass("password"), '">', Ht::password("upassword2", "", array("size" => 24)), "</div>
</div>\n";
    if ($Acct->plaintext_password()
        && ($Me->privChair || Contact::password_storage_cleartext())) {
        echo "  <div class=\"f-h\">";
        if (Contact::password_storage_cleartext())
            echo "The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don’t use a login password or any other high-security password.";
        if ($Me->privChair) {
            $Conf->footerScript("function shift_password(dir){var form=$$(\"accountform\");fold(\"account\",dir);if(form&&form.whichpassword)form.whichpassword.value=dir?\"\":\"t\";return false}");
            if (Contact::password_storage_cleartext())
                echo " <span class=\"sep\"></span>";
            echo "<span class='f-cx'><a class='fn' href='#' onclick='return shift_password(0)'>Show password</a><a class='fx' href='#' onclick='return shift_password(1)'>Hide password</a></span>";
        }
        echo "</div>\n";
    }
    echo '  <hr class="c" />';
    echo "</div></div></div>\n\n";
}

echo "</div>\n"; // f-contain


echo '<h3 class="profile">Email notification</h3>';
if ((!$newProfile && $Acct->isPC) || $Me->privChair) {
    echo "<table><tr><td>Send mail on: &nbsp;</td>",
        "<td>", Ht::checkbox_h("watchcomment", 1, !!@($formcj->follow->reviews)), "&nbsp;",
        Ht::label("Reviews and comments for authored or reviewed papers"), "</td></tr>",
        "<tr><td></td><td>", Ht::checkbox_h("watchcommentall", 1, !!@($formcj->follow->allreviews)), "&nbsp;",
        Ht::label("Reviews and comments for <i>any</i> paper"), "</td></tr>";
    if ($Me->privChair)
        echo "<tr><td></td><td>", Ht::checkbox_h("watchfinalall", 1, !!@($formcj->follow->allfinal)), "&nbsp;",
            Ht::label("Updates to final versions"), "</td></tr>";
    echo "</table>";
} else
    echo Ht::checkbox_h("watchcomment", 1, !!@($formcj->follow->reviews)), "&nbsp;",
        Ht::label("Send mail on new comments for authored or reviewed papers");


if ($newProfile || $Acct->contactId != $Me->contactId || $Me->privChair) {
    echo '<h3 class="profile">Roles</h3>', "\n",
      "<table><tr><td class=\"nowrap\">\n";
    foreach (array("chair" => "PC chair",
                   "pc" => "PC member",
                   "no" => "Not on the PC") as $k => $v) {
        echo Ht::radio_h("pctype", $k, $pcrole === $k,
                          array("id" => "pctype_$k", "onchange" => "fold('account',\$\$('pctype_no').checked,1)")),
            "&nbsp;", Ht::label($v), "<br />\n";
    }

    echo "</td><td><span class='sep'></span></td><td class='nowrap'>";
    echo Ht::checkbox_h("ass", 1, !!@($formcj->roles->sysadmin)), "&nbsp;</td>",
        "<td>", Ht::label("Sysadmin"), "<br/>",
        '<div class="hint">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</div></td></tr></table>', "\n";
}


if ($newProfile || $Acct->isPC || $Me->privChair) {
    echo '<div class="fx1"><div class="g"></div>', "\n";

    echo '<h3 class="profile">Collaborators and other affiliations</h3>', "\n",
        "<div>Please list potential conflicts of interest. ",
        $Conf->message_html("conflictdef"),
        " List one conflict per line.
    We use this information when assigning reviews.
    For example: &ldquo;<tt>Ping Yen Zhang (INRIA)</tt>&rdquo;
    or, for a whole institution, &ldquo;<tt>INRIA</tt>&rdquo;.</div>
    <textarea name='collaborators' rows='5' cols='50'>", contact_value("collaborators"), "</textarea>\n";

    $topics = $Conf->topic_map();
    if (count($topics)) {
        echo '<div id="topicinterest"><h3 class="profile">Topic interests</h3>', "\n",
            "<div class='hint'>
    Please indicate your interest in reviewing papers on these conference
    topics. We use this information to help match papers to reviewers.</div>
    <table class='topicinterest'>
       <tr><td></td><th>Low</th><th style='width:2.2em'>-</th><th style='width:2.2em'>-</th><th style='width:2.2em'>-</th><th>High</th></tr>\n";

        $interests = array(-2, -1.5,  -1, -0.5,  0, 1,  2, 3,  4);
        foreach ($topics as $id => $name) {
            echo "      <tr><td class=\"ti_topic\">", htmlspecialchars($name), "</td>";
            $ival = @((int) $formcj->topics->$id);
            for ($xj = 0; $xj + 1 < count($interests) && $ival > $interests[$xj + 1]; $xj += 2)
                /* nothing */;
            for ($j = 0; $j < count($interests); $j += 2)
                echo "<td class='ti_interest'>", Ht::radio_h("ti$id", $interests[$j], $j == $xj), "</td>";
            echo "</td></tr>\n";
        }
        echo "    </table></div>\n";
    }


    if ($Me->privChair || @$formcj->tags) {
        if (is_object(@$formcj->tags))
            $tags = array_keys(get_object_vars($formcj->tags));
        else if (is_array(@$formcj->tags))
            $tags = $formcj->tags;
        else
            $tags = array();
        echo "<h3 class=\"profile\">Tags</h3>\n";
        if ($Me->privChair) {
            echo "<div class='", feclass("contactTags"), "'>",
                textinput("contactTags", join(" ", $tags), 60),
                "</div>
  <div class='hint'>Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href='", hoturl("settings", "group=rev&amp;tagcolor=1#tagcolor"), "'>tag colors</a> to highlight subgroups in review lists.</div>\n";
        } else {
            echo join(" ", $tags), "
  <div class='hint'>Tags represent PC subgroups and are set by administrators.</div>\n";
        }
    }
    echo "</div>\n"; // fx1
}


echo "<div class='aa'><table class='pt_buttons'>\n";
$buttons = array(Ht::submit("register", $newProfile ? "Create account" : "Save changes", array("class" => "bb")));
if ($Me->privChair && !$newProfile && $Me->contactId != $Acct->contactId) {
    $tracks = databaseTracks($Acct->contactId);
    $buttons[] = array(Ht::js_button("Delete user", "popup(this,'d',0)"), "(admin only)");
    if (count($tracks->soleAuthor)) {
        $Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p><strong>This user cannot be deleted</strong> because they are the sole
  contact for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ".
  Delete these papers from the database or add alternate paper contacts and
  you will be able to delete this user.</p>
  <div class='popup_actions'>"
    . Ht::js_button("Close", "popup(null,'d',1)")
    . "</div></div>");
    } else {
        if (count($tracks->author) + count($tracks->review) + count($tracks->comment)) {
            $x = $y = array();
            if (count($tracks->author)) {
                $x[] = "contact for " . pluralx($tracks->author, "paper") . " " . textArrayPapers($tracks->author);
                $y[] = "delete " . pluralx($tracks->author, "this") . " " . pluralx($tracks->author, "authorship association");
            }
            if (count($tracks->review)) {
                $x[] = "reviewer for " . pluralx($tracks->review, "paper") . " " . textArrayPapers($tracks->review);
                $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->review, "this") . " " . pluralx($tracks->review, "review");
            }
            if (count($tracks->comment)) {
                $x[] = "commenter for " . pluralx($tracks->comment, "paper") . " " . textArrayPapers($tracks->comment);
                $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->comment, "this") . " " . pluralx($tracks->comment, "comment");
            }
            $dialog = "<p>This user is " . commajoin($x) . ".
  Deleting the user will also " . commajoin($y) . ".</p>";
        } else
            $dialog = "";
        $Conf->footerHtml("<div id='popup_d' class='popupc'>
  <p>Be careful: This will permanently delete all information about this
  user from the database and <strong>cannot be undone</strong>.</p>
  $dialog
  <form method='post' action=\"" . hoturl_post("profile", "u=" . urlencode($Acct->email)) . "\" enctype='multipart/form-data' accept-charset='UTF-8'>
    <div class='popup_actions'>"
      . Ht::js_button("Cancel", "popup(null,'d',1)")
      . Ht::submit("delete", "Delete user", array("class" => "bb"))
      . "</div></form></div>");
    }
}
if (!$newProfile && $Acct->contactId == $Me->contactId)
    $buttons[] = Ht::submit("merge", "Merge with another account",
                            array("style" => "margin-left:2ex"));
echo "    <tr>\n";
foreach ($buttons as $b) {
    $x = (is_array($b) ? $b[0] : $b);
    echo "      <td class='ptb_button'>", $x, "</td>\n";
}
echo "    </tr>\n    <tr>\n";
foreach ($buttons as $b) {
    $x = (is_array($b) ? $b[1] : "");
    echo "      <td class='ptb_explain'>", $x, "</td>\n";
}
echo "    </tr>\n    </table></div>\n";

echo "</div>\n", // foldaccount
    "</div>\n", // aahc
    "</form>\n";

if ($newProfile) {
    echo '</div><div class="fx9">';
    echo Ht::form(hoturl_post("profile", join("&amp;", $form_params)),
                  array("id" => "accountform", "autocomplete" => "off")),
        "<div class='profiletext aahc", ($UserStatus->nerrors ? " alert" : "") , "'>\n",
        // Don't want chrome to autofill the password changer.
        // But chrome defaults to autofilling the password changer
        // unless we supply an earlier password input.
        Ht::password("chromefooler", "", array("style" => "display:none"));
    create_modes(true);

    $bulkentry = @$_REQUEST["bulkentry"];
    if ($bulkentry === null
        && ($session_bulkentry = $Conf->session("profile_bulkentry"))
        && is_array($session_bulkentry) && $session_bulkentry[0] > $Now - 5) {
        $bulkentry = $session_bulkentry[1];
        $Conf->save_session("profile_bulkentry", null);
    }
    echo '<div class="f-contain"><div class="f-i">',
        '<div class="f-e">', Ht::textarea("bulkentry", $bulkentry,
                                          array("rows" => 1, "cols" => 80, "placeholder" => "Enter users one per line")),
        '</div></div></div>';

    echo '<div class="g"><strong>OR</strong> &nbsp;',
        '<input type="file" name="bulk" size="30" /></div>';

    echo '<div>', Ht::submit("bulkregister", "Save accounts"), '</div>';

    echo "<p>Enter or upload CSV data for new users, including a header to explain your format. For example:</p>\n",
        '<pre class="entryexample">
name,email,affiliation,roles
John Adams,john@earbox.org,UC Berkeley,pc
"Adams, John Quincy",quincy@whitehouse.gov
</pre>', "\n",
        '<p>Or just enter an email address per line.</p>',
        '<p>Supported CSV fields include:</p><table>',
        '<tr><td class="lmcaption"><code>name</code></td>',
          '<td>User name</td></tr>',
        '<tr><td class="lmcaption"><code>first</code></td>',
          '<td>First name</td></tr>',
        '<tr><td class="lmcaption"><code>last</code></td>',
          '<td>Last name</td></tr>',
        '<tr><td class="lmcaption"><code>affiliation</code></td>',
          '<td>Affiliation</td></tr>',
        '<tr><td class="lmcaption"><code>roles</code></td>',
          '<td>User roles: blank, “<code>pc</code>”, “<code>chair</code>”, or “<code>sysadmin</code>”</td></tr>',
        '<tr><td class="lmcaption"><code>tags</code></td>',
          '<td>PC tags (space-separated)</td></tr>',
        '<tr><td class="lmcaption"><code>collaborators</code></td>',
          '<td>Collaborators</td></tr>',
        '<tr><td class="lmcaption"><code>follow</code></td>',
          '<td>Email notification: blank, “<code>reviews</code>”, “<code>allreviews</code>”</td></tr>',
        "</table>\n";

    echo '</div></form></div></div>';
}


$Conf->footerScript('hiliter_children("#accountform");$("textarea").autogrow()');
if ($newProfile)
    $Conf->footerScript('if(/bulk/.test(location.hash))fold("bulk",false,9)');
$Conf->footerScript('crpfocus("account")');
$Conf->footer();