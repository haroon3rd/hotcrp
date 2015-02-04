<?php
// api.php -- HotCRP JSON API access page
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

if (@$_REQUEST["key"] && isset($Opt["buzzerkey"]) && $Opt["buzzerkey"] == $_REQUEST["key"])
    $Me = Contact::site_contact();

if (@$_REQUEST["track"] && $Me->privChair && check_post()) {
    // arguments: IDENTIFIER LISTNUM [POSITION] -OR- stop
    if ($_REQUEST["track"] == "stop")
        MeetingTracker::clear();
    else {
        $args = preg_split('/\s+/', $_REQUEST["track"]);
        if (count($args) >= 2
            && ($xlist = SessionList::lookup($args[1]))) {
            $position = null;
            if (count($args) >= 3 && ctype_digit($args[2]))
                $position = array_search((int) $args[2], $xlist->ids);
            MeetingTracker::update($xlist, $args[0], $position);
        }
    }
}

if (@$_GET["p"] && ctype_digit($_GET["p"])) {
    $CurrentProw = $Conf->paperRow(array("paperId" => intval($_GET["p"])), $Me);
    if ($CurrentProw && !$Me->can_view_paper($CurrentProw))
        $CurrentProw = null;
}


$j = $Me->my_deadlines($CurrentProw);

if (@$j->tracker && $Me->privChair && @$_REQUEST["pc_conflicts"])
    MeetingTracker::status_add_pc_conflicts($j->tracker);
if (@$_REQUEST["checktracker"]) {
    $tracker = @$j->tracker ? : $Conf->setting_json("tracker");
    $j->tracker_status = MeetingTracker::tracker_status($tracker);
}
if (@$_REQUEST["conflist"] && $Me->has_email() && ($cdb = Contact::contactdb())) {
    $j->conflist = array();
    $result = Dbl::ql($cdb, "select c.confid, siteclass, shortName, url
        from Roles r join Conferences c on (c.confid=r.confid)
        join ContactInfo u on (u.contactDbId=r.contactDbId)
        where u.email=? order by r.updated_at desc", $Me->email);
    while (($row = edb_orow($result))) {
        $row->confid = (int) $row->confid;
        $j->conflist[] = $row;
    }
}
if (@$_REQUEST["jserror"] && @$_REQUEST["error"]) {
    $url = defval($_REQUEST, "url", "");
    if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m))
        $url = $m[1];
    if (isset($_REQUEST["lineno"]) && $_REQUEST["lineno"] != "0")
        $url .= ":" . $_REQUEST["lineno"];
    if (isset($_REQUEST["colno"]) && $_REQUEST["colno"] != "0")
        $url .= ":" . $_REQUEST["colno"];
    if ($url != "")
        $url .= ": ";
    $errormsg = trim($_REQUEST["error"]);
    $suffix = "";
    if ($Me->email)
        $suffix .= ", user $Me->email";
    error_log("JS error: $url$errormsg$suffix");
    if (isset($_REQUEST["stack"])) {
        $stack = array();
        foreach (explode("\n", $_REQUEST["stack"]) as $line) {
            $line = trim($line);
            if ($line === "" || $line === $errormsg || "Uncaught $line" === $errormsg)
                continue;
            if (preg_match('/\Aat (\S+) \((\S+)\)/', $line, $m))
                $line = $m[1] . "@" . $m[2];
            else if (substr($line, 0, 1) === "@")
                $line = substr($line, 1);
            else if (substr($line, 0, 3) === "at ")
                $line = substr($line, 3);
            $stack[] = $line;
        }
        error_log("JS error: {$url}via " . join(" ", $stack));
    }
}

$j->ok = true;
if (@$j->tracker) /* microsecond time precision is called for! */
    $j->now = microtime(true);
$Conf->ajaxExit($j);
