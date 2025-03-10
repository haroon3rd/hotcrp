<?php
// listactions/la_getallrevpref.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetAllRevpref_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }

    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        // Reduce memory requirements by prefetching has_expertise and has_interest
        list($has_expertise, $has_interest) = $user->conf->fetch_first_row("select exists (select * from PaperReviewPreference where expertise is not null) has_expertise, exists (select * from TopicInterest where interest!=0) has_interest from dual");

        $headers = [
            "paper", "title",
            "first", "last", "email",
            "conflict", "preference"
        ];
        if ($has_expertise) {
            $headers[] = "expertise";
        }
        if ($has_interest) {
            $headers[] = "topic_score";
        }

        $csvg = $user->conf->make_csvg("allprefs")->set_header($headers);
        $pcm = $user->conf->pc_members();
        foreach ($ssel->paper_set($user, ["allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1]) as $prow) {
            if (!$user->allow_administer($prow)) {
                continue;
            }
            $conflicts = $prow->conflicts();
            foreach ($pcm as $cid => $p) {
                $pref = $prow->preference($p);
                $cflt = $conflicts[$cid] ?? null;
                $is_cflt = $cflt && $cflt->is_conflicted();
                $ts = $prow->topicIds !== "" ? $prow->topic_interest_score($p) : 0;
                if ($pref[0] !== 0 || $pref[1] !== null || $is_cflt || $ts !== 0) {
                    $l = [
                        $prow->paperId, $prow->title,
                        $p->firstName, $p->lastName, $p->email,
                        $is_cflt ? "conflict" : "", $pref[0] ? : ""
                    ];
                    if ($has_expertise) {
                        $l[] = unparse_expertise($pref[1]);
                    }
                    if ($has_interest) {
                        $l[] = $ts ? : "";
                    }
                    $csvg->add_row($l);
                }
            }
        }
        return $csvg;
    }
}
