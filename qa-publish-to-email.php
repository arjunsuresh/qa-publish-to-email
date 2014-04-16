<?php

/*
	Ali Tavakoli

	File: qa-plugin/qa-publish-to-email/qa-publish-to-email.php
	Version: 0.1
	Date: 2014-04-11
	Description: Event module class for publishing questions/answers/comments to email
*/



class qa_publish_to_email_event
{
	function option_default($option)
	{
		if ($option == 'plugin_publish2email_emails')
			return '';

		if ($option == 'plugin_publish2email_fav_categories_only')
			return false;

		if ($option == 'plugin_publish2email_use_bcc')
			return false;

		if ($option == 'plugin_publish2email_plaintext_only')
			return false;

		if ($option == 'plugin_publish2email_show_trail')
			return true;
	}

	function admin_form(&$qa_content)
	{
		$saved = false;

		if (qa_clicked('plugin_publish2email_save_button'))
		{
			qa_opt('plugin_publish2email_emails', qa_post_text('plugin_publish2email_emails_field'));
			qa_opt('plugin_publish2email_fav_categories_only', (int)qa_post_text('plugin_publish2email_fav_cats_field'));
			qa_opt('plugin_publish2email_use_bcc', (int)qa_post_text('plugin_publish2email_use_bcc_field'));
			qa_opt('plugin_publish2email_plaintext_only', (int)qa_post_text('plugin_publish2email_plaintext_only_field'));
			qa_opt('plugin_publish2email_show_trail', (int)qa_post_text('plugin_publish2email_show_trail_field'));
			$saved = true;
		}

		return array(
			'ok' => $saved ? 'Settings saved' : null,

			'fields' => array(
				array(
					'label' => 'Notification email addresses:',
					'type' => 'text',
					'value' => qa_opt('plugin_publish2email_emails'),
					'suffix' => '(separate multiple emails with commas or semicolons)',
					'tags' => 'NAME="plugin_publish2email_emails_field"',
				),
				array(
					'label' => 'Only send emails for favorite categories (email addresses must be registered)',
					'type' => 'checkbox',
					'value' => qa_opt('plugin_publish2email_fav_categories_only'),
					'tags' => 'NAME="plugin_publish2email_fav_cats_field"',
				),
				array(
					'label' => 'Use Bcc instead of To for emails',
					'type' => 'checkbox',
					'value' => qa_opt('plugin_publish2email_use_bcc'),
					'tags' => 'NAME="plugin_publish2email_use_bcc_field"',
				),
				array(
					'label' => 'Send emails as plain-text',
					'type' => 'checkbox',
					'value' => qa_opt('plugin_publish2email_plaintext_only'),
					'tags' => 'NAME="plugin_publish2email_plaintext_only_field"',
				),
				array(
					'label' => 'Include dependent posts in email body (e.g. include questions when sending emails for answers)',
					'type' => 'checkbox',
					'value' => qa_opt('plugin_publish2email_show_trail'),
					'tags' => 'NAME="plugin_publish2email_show_trail_field"',
				),
			),

			'buttons' => array(
				array(
					'label' => 'Save Changes',
					'tags' => 'NAME="plugin_publish2email_save_button"',
				),
			),
		);
	}

	function process_event($event, $userid, $handle, $cookieid, $params)
	{
		require_once QA_INCLUDE_DIR.'qa-class.phpmailer.php';
		require_once QA_INCLUDE_DIR.'qa-app-format.php';
		require_once QA_INCLUDE_DIR.'qa-util-string.php';

		switch ($event)
		{
		case 'q_post':
			$subject = $params['title'];
			$url = qa_q_path($params['postid'], $params['title'], true);

			// fall through instead of breaking
		case 'a_post':
			if (!isset($subject))
				$subject = "RE: " . $params['parent']['title'];

			if (!isset($url))
				$url = qa_q_path($params['parent']['postid'], $params['parent']['title'], true, 'A', $params['postid']);

			// fall through instead of breaking
		case 'c_post':
			if (!isset($subject))
				$subject = "RE: " . $params['question']['title'];

			if (!isset($url))
				$url = qa_q_path($params['question']['postid'], $params['question']['title'], true, 'C', $params['postid']);

			// Get the configured list of emails and split by commas/semi-colons (and possible whitespace)
			$emails = preg_split('/[,;] */', qa_opt('plugin_publish2email_emails'), -1, PREG_SPLIT_NO_EMPTY);

			if (count($emails) == 0)
				return;

			// Get the poster's info
			$user=$this->qa_db_userinfo($userid);

			// Filter for emails that have this post's category as favorite
			if (qa_opt('plugin_publish2email_fav_categories_only'))
				$emails = $this->qa_db_favorite_category_emails($emails, $params['categoryid']);

			$mailer=new PHPMailer();
			$mailer->CharSet='utf-8';

			$mailer->Sender=qa_opt('from_email');
			$mailer->From=(isset($user['email']) ? $user['email'] : qa_opt('from_email'));
			$mailer->FromName=(isset($user['name']) ? $user['name'] : (isset($handle) ? $handle : qa_opt('site_title')));
			$mailer->AddReplyTo(qa_opt('from_email'), qa_opt('site_title') . ' (Do Not Reply)');

			// Explicitly add the Sender (aka the "On behalf of") header, since this version of phpmailer
			// doesn't do it (it helps with defining folder rules)
			$mailer->AddCustomHeader('Sender:'.qa_opt('from_email'));

			if (qa_opt('plugin_publish2email_use_bcc'))
			{
				foreach ($emails as $email)
				{
					$mailer->AddBCC($email);
				}
			}
			else
			{
				foreach ($emails as $email)
				{
					$mailer->AddAddress($email);
				}
			}

			$mailer->Subject=$subject;


			// If any of the posts that need to be put in the body are HTML, make everything HTML
			$isanyposthtml=($params['format'] === 'html');
			if (qa_opt('plugin_publish2email_show_trail'))
			{
				switch ($event)
				{
				case 'c_post':
					// For comments, check both the parent and the question
					// (which might be the same post, but it doesn't change the result)
					$isanyposthtml=$isanyposthtml || ($params['question']['format'] === 'html');
					// fall through
				case 'a_post':
					// For answers, just check the parent, which is the question
					$isanyposthtml=$isanyposthtml || ($params['parent']['format'] === 'html');
					break;
				}
			}

			$ishtml=($isanyposthtml && !qa_opt('plugin_publish2email_plaintext_only'));

			// Add the body and add a plaintext AltBody for HTML emails
			$mailer->IsHTML($ishtml);
			$mailer->Body=$this->qa_build_body($event, $url, $params, $ishtml);
			if ($ishtml)
				$mailer->AltBody=$this->qa_build_body($event, $url, $params, false);

			if (qa_opt('smtp_active'))
			{
				$mailer->IsSMTP();
				$mailer->Host=qa_opt('smtp_address');
				$mailer->Port=qa_opt('smtp_port');
			}

			if (qa_opt('smtp_secure'))
				$mailer->SMTPSecure=qa_opt('smtp_secure');

			if (qa_opt('smtp_authenticate'))
			{
				$mailer->SMTPAuth=true;
				$mailer->Username=qa_opt('smtp_username');
				$mailer->Password=qa_opt('smtp_password');
			}

			$mailer->Send();
		}
	}

	function qa_db_userinfo($userid)
	{
		require_once QA_INCLUDE_DIR.'qa-db-selects.php';

		list($user,$useremail) = qa_db_select_with_pending(
			qa_db_user_profile_selectspec($userid, true),
			array(
				'columns' => array('email' => '^users.email'),
				'source' => "^users WHERE ^users.userid=$",
				'arguments' => array($userid),
			));
		$user['email'] = @$useremail[0]['email'];

		return $user;
	}

	function qa_db_category_favorite_emails($emails, $categoryid)
	{
		require_once QA_INCLUDE_DIR.'qa-app-updates.php';
		require_once QA_INCLUDE_DIR.'qa-db-selects.php';

		return qa_db_select_with_pending(array(
			'columns' => array('email' => 'DISTINCT ^users.email'),
			'source' => "^users JOIN ^userfavorites USING (userid) WHERE ^users.email IN ($) AND ^userfavorites.entityid=$ AND ^userfavorites.entitytype=$",
			'arguments' => array($emails, $categoryid, QA_ENTITY_CATEGORY),
		));
	}

	function qa_format_post($params, $ishtml)
	{
		if (isset($params['text']))
			$text = $params['text'];
		else
			$text = qa_post_content_to_text($params['content'], $params['format']);

		if ($ishtml)
		{
			if ($params['format'] === 'html')
				return $params['content'];
			else
				return '<pre>'.$text.'</pre>';
		}
		else
		{
			return $text;
		}
	}

	function qa_format_header($preamble, $title, $ishtml)
	{
		if ($ishtml)
			return '<hr><h2>'.$preamble.'</h2><h3>'.qa_html($title).'</h3>';
		else
			return "\n\n===\n\n".$preamble."\n\n".$title."\n\n";
	}

	function qa_format_footer($preamble, $title, $url, $ishtml)
	{
		if ($ishtml)
			return '<hr><p><strong>'.$preamble.'<a href="'.$url.'">'.$title.'</a>.</strong></p>';
		else
			return "\n\n===\n\n".$preamble."\n".$url."\n";
	}

	function qa_build_body($event, $url, $params, $ishtml)
	{
		$body=$this->qa_format_post($params, $ishtml);

		if (qa_opt('plugin_publish2email_show_trail'))
		{
			switch ($event)
			{
			case 'a_post':
				$body.=$this->qa_format_header('The above was an answer to this question:', $params['parent']['title'], $ishtml);
				$body.=$this->qa_format_post($params['parent'], $ishtml);
				break;
			case 'c_post':
				if ($params['parent']['type'] == 'Q')
				{
					$body.=$this->qa_format_header('The above was a comment on this question:', $params['parent']['title'], $ishtml);
					$body.=$this->qa_format_post($params['parent'], $ishtml);
				}
				else
				{
					$body.=$this->qa_format_header('The above was a comment on this answer:', '', $ishtml);
					$body.=$this->qa_format_post($params['parent'], $ishtml);

					$body.=$this->qa_format_header('Original question:', $params['question']['title'], $ishtml);
					$body.=$this->qa_format_post($params['question'], $ishtml);
				}
				break;
			}
		}

		return $body.$this->qa_format_footer("View the entire conversation or reply at ", "this link", $url, $ishtml);
	}

};

/*
	Omit PHP closing tag to help avoid accidental output
*/
