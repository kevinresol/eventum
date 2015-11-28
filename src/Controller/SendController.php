<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2015 Eventum Team.                                     |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 51 Franklin Street, Suite 330                                        |
// | Boston, MA 02110-1301, USA.                                          |
// +----------------------------------------------------------------------+

namespace Eventum\Controller;

use Access;
use Attachment;
use Auth;
use Draft;
use Email_Account;
use Email_Response;
use History;
use Issue;
use Mail_Helper;
use Misc;
use Notification;
use Prefs;
use Project;
use Status;
use Support;
use Time_Tracking;
use User;
use Workflow;

/**
 * Class handling send.php
 */
class SendController extends BaseController
{
    /** @var string */
    protected $tpl_name = 'send.tpl.html';

    /** @var string */
    private $cat;

    /** @var int */
    private $issue_id;

    /** @var int */
    private $usr_id;

    /** @var int */
    private $prj_id;

    /**
     * create variables from request, etc
     */
    protected function configure()
    {
        $request = $this->getRequest();
        $this->issue_id = (int)$request->get('issue_id');
        $this->cat = $request->request->get('cat') ?: $request->query->get('cat');
        $this->prj_id = Auth::getCurrentProject();
        $this->usr_id = Auth::getUserID();
    }

    protected function canAccess()
    {
        Auth::checkAuthentication();

        return Issue::canAccess($this->issue_id, $this->usr_id);
    }

    protected function defaultAction()
    {
        Workflow::prePage($this->prj_id, 'send_email');

        // since emails associated with issues are sent to the notification list,
        // not the to: field, set the to field to be blank
        // this field should already be blank, but may also be unset.
        // FIXME: move this to proper 'cat' action
        if ($this->issue_id) {
            $_POST['to'] = '';
        }

        switch ($this->cat) {
            case 'send_email':
                $this->sendEmailAction();
                break;
            case 'save_draft':
                $this->saveDraftAction();
                break;
            case 'update_draft':
                $this->updateDraftAction();
                break;
            case 'view_draft':
                $this->viewDraftAction();
                break;
            case 'create_draft':
                $this->createDraftAction();
                break;
            default:
                $this->otherAction();
        }

        if ($this->cat == 'reply') {
            $this->replyAction();
        }
    }

    protected function prepareTemplate()
    {
        if ($this->issue_id) {
            $sender_details = User::getDetails($this->usr_id);
            // list the available statuses
            $this->tpl->assign(
                array(
                    'issue_id' => $this->issue_id,

                    'statuses' => Status::getAssocStatusList($this->prj_id, false),
                    'current_issue_status' => Issue::getStatusID($this->issue_id),
                    // set if the current user is allowed to send emails on this issue or not
                    'can_send_email' => Support::isAllowedToEmail($this->issue_id, $sender_details['usr_email']),
                    'subscribers' => Notification::getSubscribers($this->issue_id, 'emails'),
                )
            );
        }

        $this->tpl->assign('ema_id', $this->getRequest()->get('ema_id'));

        $user_prefs = Prefs::get($this->usr_id);
        // list of users to display in the lookup field in the To: and Cc: fields
        $address_book = Project::getAddressBook($this->prj_id, $this->issue_id);

        $this->tpl->assign(
            array(
                'from' => User::getFromHeader($this->usr_id),
                'assoc_users' => $address_book,
                'assoc_emails' => array_keys($address_book),
                'canned_responses' => Email_Response::getAssocList($this->prj_id),
                'js_canned_responses' => Email_Response::getAssocListBodies($this->prj_id),
                'current_user_prefs' => $user_prefs,
                'issue_access' => Access::getIssueAccessArray($this->issue_id, $this->usr_id),
                'max_attachment_size' => Attachment::getMaxAttachmentSize(),
                'max_attachment_bytes' => Attachment::getMaxAttachmentSize(true),
                'time_categories' => Time_Tracking::getAssocCategories($this->prj_id),
                'email_category_id' => Time_Tracking::getCategoryId($this->prj_id, 'Email Discussion'),
            )
        );

        // don't add signature if it already exists. Note: This won't handle multiple user duplicate sigs.
        if (!empty($draft['emd_body']) && $user_prefs['auto_append_email_sig'] == 1
            && strpos($draft['emd_body'], $user_prefs['email_signature']) !== false
        ) {
            $this->tpl->assign('body_has_sig_already', 1);
        }
    }

    private function sendEmailAction()
    {
        $post = $this->getRequest()->request;

        $res = Support::sendEmailFromPost($post->get('parent_id'));
        $this->tpl->assign('send_result', $res);

        $new_status = $post->get('new_status');
        if ($new_status && Access::canChangeStatus($this->issue_id, $this->usr_id)) {
            $res = Issue::setStatus($this->issue_id, $new_status);
            if ($res != -1) {
                $status_title = Status::getStatusTitle($new_status);
                History::add(
                    $this->issue_id, $this->usr_id, 'status_changed',
                    "Status changed to '{status}' by {user} when sending an email", array(
                        'status' => $status_title,
                        'user' => User::getFullName($this->usr_id),
                    )
                );
            }
        }

        // remove the existing email draft, if appropriate
        $draft_id = $post->getInt('draft_id');
        if ($draft_id) {
            Draft::remove($draft_id);
        }

        // enter the time tracking entry about this new email
        $summary = ev_gettext('Time entry inserted when sending outgoing email.');
        $this->addTimeTracking($summary);

        return true;
    }

    private function saveDraftAction()
    {
        $post = $this->getRequest()->request;

        $res = Draft::saveEmail(
            $this->issue_id,
            $post->get('to'), $post->get('cc'), $post->get('subject'), $post->get('message'),
            $post->get('parent_id')
        );
        $this->tpl->assign('draft_result', $res);

        $summary = ev_gettext('Time entry inserted when saving an email draft.');
        $this->addTimeTracking($summary);
    }

    private function updateDraftAction()
    {
        $post = $this->getRequest()->request;
        $res = Draft::update(
            $this->issue_id,
            $post->get('draft_id'), $post->get('to'), $post->get('cc'), $post->get('subject'), $post->get('message'),
            $post->get('parent_id')
        );
        $this->tpl->assign('draft_result', $res);

        $summary = ev_gettext('Time entry inserted when saving an email draft.');
        $this->addTimeTracking($summary);
    }

    private function viewDraftAction()
    {
        $draft = Draft::getDetails($_GET['id']);
        $email = array(
            'sup_subject' => $draft['emd_subject'],
            'seb_body' => $draft['emd_body'],
            'sup_from' => $draft['to'],
            'cc' => implode('; ', $draft['cc']),
        );

        // try to guess the correct email account to be associated with this email
        if (!empty($draft['emd_sup_id'])) {
            $_GET['ema_id'] = Email_Account::getAccountByEmail($draft['emd_sup_id']);
        } else {
            // if we are not replying to an existing message, just get the first email account you can find...
            $_GET['ema_id'] = Email_Account::getEmailAccount();
        }

        $this->tpl->assign(
            array(
                'draft_id' => $_GET['id'],
                'email' => $email,
                'parent_email_id' => $draft['emd_sup_id'],
                'draft_status' => $draft['emd_status'],
            )
        );

        if ($draft['emd_status'] != 'pending') {
            $this->tpl->assign('read_only', 1);
        }
    }

    private function createDraftAction()
    {
        $this->tpl->assign('hide_email_buttons', 'yes');
    }

    private function otherAction()
    {
        $get = $this->getRequest()->query;

        if (!$get->has('id')) {
            return;
        }

        $email = Support::getEmailDetails($get->getInt('ema_id'), $get->getInt('id'));
        $header = Misc::formatReplyPreamble($email['timestamp'], $email['sup_from']);
        $email['seb_body'] = $header . Misc::formatReply($email['seb_body']);
        $this->tpl->assign(
            array(
                'email' => $email,
                'parent_email_id' => $get->getInt('id'),
            )
        );
    }

    /**
     * special handling when someone tries to 'reply' to an issue
     */
    private function replyAction()
    {
        $details = Issue::getReplyDetails($this->issue_id);
        if (!$details) {
            return;
        }

        $header = Misc::formatReplyPreamble($details['created_date_ts'], $details['reporter']);
        $details['seb_body'] = $header . Misc::formatReply($details['description']);
        $details['sup_from'] = Mail_Helper::getFormattedName($details['reporter'], $details['reporter_email']);
        $this->tpl->assign(
            array(
                'email' => $details,
                'parent_email_id' => 0,
                // TODO: translate
                'extra_title' => "Issue #{$this->issue_id}: Reply",
            )
        );
    }

    /**
     * Enter the time tracking entry about this new email
     */
    private function addTimeTracking($default_summary)
    {
        $post = $this->getRequest()->request;

        if (!$post->has('time_spent')) {
            return;
        }

        $summary = $post->get('time_summary') ?: $default_summary;
        $ttc_id = (int)$post->get('time_category');
        $time_spent = (int)$post->get('time_spent');
        Time_Tracking::addTimeEntry($this->issue_id, $ttc_id, $time_spent, null, $summary);
    }
}