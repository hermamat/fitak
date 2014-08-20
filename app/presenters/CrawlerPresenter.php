<?php

/**
 * Description of CrawlerPresenter
 * Here is everything about getting data from Facebook...
 *
 * @author Vojtech Miksu <vojtech@miksu.cz>
 */
use Nette\Diagnostics\Debugger;
use Nette\Utils\Random;

class CrawlerPresenter extends BasePresenter
{

	private $gid, $token, $nextPage, $cnt_inserted = 0, $cnt_updated = 0, $cnt_downloaded = 0;
	private $facebook;

	// getting data (topics, comments and likes) from Facebook groups
	public function actionDefault()
	{
		@set_time_limit(600);
		$this->token = $this->context->token->getToken();
		if (!$this->token)
		{
			$this->redirect('Crawler:token');
		}

		$config = [];
		$config['appId'] = $this->context->token->getAppId();
		$config['secret'] = $this->context->token->getAppSecret();
		$this->facebook = new Facebook($config);
		$this->facebook->setAccessToken($this->token);

		foreach ($this->context->groups->getList() as $group)
		{
			$this->gid = $group->id;
			$this->nextPage = NULL;
			echo "<h2>Checking group: $group->name </h2>";
			$this->startGrabbing();
			$this->cnt_inserted = $this->cnt_updated = $this->cnt_downloaded = 0;
		}
		$this->terminate();
	}

	// getting token from Facebook
	public function actionToken($state, $code)
	{
		$tokenManager = $this->context->token;
		$ip = $this->getHttpRequest()->getRemoteAddress();

		if (!$tokenManager->checkAccess($ip))
		{
			echo "Oh sorry man, this is a private party!";
			mail($tokenManager->getEmail(), 'Notice', 'The token is maybe invalid!');
			$this->error(NULL, 403);
		}

		$session = $this->session->getSection('crawler.token');
		if ($code === NULL)
		{
			$session->state = Random::generate();
			$this->redirectUrl('https://www.facebook.com/dialog/oauth?' . http_build_query([
				'client_id' => $tokenManager->getAppId(),
				'redirect_uri' => $this->link('//Crawler:token'),
				'scope' => $tokenManager->getAppPermissions(),
				'state' => $session->state,
			]));
		}

		if (empty($session->state) || $session->state !== $state)
		{
			echo "The state does not match. You may be a victim of CSRF.";
			$this->error(NULL, 403);
		}

		$response = file_get_contents('https://graph.facebook.com/oauth/access_token?' . http_build_query([
			'client_id' => $tokenManager->getAppId(),
			'client_secret' => $tokenManager->getAppSecret(),
			'redirect_uri' => $this->link('//Crawler:token'),
			'code' => $code,
		]));

		$params = NULL;
		parse_str($response, $params);

		$date = new DateTime();
		$date->add(new DateInterval('PT' . $params['expires'] . 'S'));
		$tokenManager->saveToken($params['access_token'], $date);

		echo "Thanks for your token :)";
		$this->terminate();
	}

	private function startGrabbing()
	{
		while ($this->grabPage())
		{
			echo "Inserted: " . $this->cnt_inserted . " | Updated: " . $this->cnt_updated . "<br />";
			//@ob_flush();
			//@flush();
		}
	}

	// proccess one block of JSON code (25 messages from feed)
	private function grabPage()
	{
		$data = $this->getJson();


		if (!$this->nextPage)
		{
			echo "The last page";

			return FALSE;
		}
		//echo "JSONS downloaded: " . $this->cnt_downloaded . "<br />";
		//@ob_flush();
		//@flush();
		foreach ($data['data'] as $message)
		{
			// fix timezone issue
			$message['created_time'] = date(DATE_ISO8601, strtotime($message['created_time']));
			$message['updated_time'] = date(DATE_ISO8601, strtotime($message['updated_time']));

			$ids = explode("_", $message['id']);
			$datetime = NULL;
			$datetime = $this->context->data->getUpdatedTime($ids[1]);

			if (strtotime($datetime) == strtotime($message['updated_time']))
			{
				echo $this->cnt_inserted . ' messages was saved and ' . $this->cnt_updated . '
                    updated.<br />';

				return FALSE;
			}
			$mess = "";
			$likes_count = 0;

			$link = NULL;
			$source = NULL;
			$picture = NULL;
			$name = NULL;
			$caption = NULL;
			$description = NULL;

			if (isSet($message['message']))
			{
				$mess = $message['message'];
			}

			$commentsNum = 0;
			if (isSet ($message['comments']))
			{
				$commentsNum = count($message['comments']);
			}

			if (isSet ($message['link']))
			{
				$link = $message['link'];
			}

			if (isSet ($message['source']))
			{
				$source = $message['source'];
			}

			if (isSet ($message['picture']))
			{
				$picture = $message['picture'];
			}

			if (isSet ($message['name']))
			{
				$name = $message['name'];
			}

			if (isSet ($message['caption']))
			{
				$caption = $message['caption'];
			}

			if (isSet ($message['description']))
			{
				$description = $message['description'];
			}

			if (!$datetime)
			{
				$this->context->data->insertTopic(
					$ids[1],
					$this->gid,
					0,
					$mess,
					$message['created_time'],
					$message['updated_time'],
					$commentsNum,
					0,
					$message['from']['id'],
					$message['from']['name'],
					$message['type'],
					$link,
					$source,
					$picture,
					$name,
					$caption,
					$description
				);
				$this->saveTags($mess, $ids[1]);
				$this->cnt_inserted++;
			}
			else
			{
				$this->context->data->updateTopic(
					$ids[1],
					$mess,
					$message['updated_time'],
					$commentsNum,
					0);
				$this->cnt_updated++;
			}

			if (isSet($message['comments']['data']) && count($message['comments']['data']))
			{
				$this->addComments($message['comments']['data'], $ids[1]);

				$next = FALSE;
				if (isSet($message['comments']['paging']['next']))
				{
					$next = $message['comments']['paging']['next'];
				}

				while ($next)
				{
					$url = $next . '&access_token=' . $this->token;
					$extraComments = json_decode(file_get_contents($url), TRUE);
					$next = FALSE;
					if (isSet($extraComments['data']))
					{
						$this->addComments($extraComments['data'], $ids[1]);
						if (isSet($extraComments['paging']['previous']))
						{
							$next = $extraComments['paging']['previous'];
						}
					}
				}
			}
		}

		return TRUE;
	}

	// save comments
	private function addComments($comments, $topicId)
	{
		foreach ($comments as $comment)
		{
			// fix timezone issue
			$comment['created_time'] = date(DATE_ISO8601, strtotime($comment['created_time']));
			$mess = "";
			if (isSet($comment['message']))
			{
				$mess = $comment['message'];
			}
			$likes_count = 0;

			if ($this->context->data->existsComment($comment['id'], $topicId))
			{
				$this->context->data->updateComment($comment['id'], $topicId, $mess, $likes_count);
				$this->cnt_updated++;
			}
			else
			{
				$this->context->data->insertComment($comment['id'], $this->gid, $topicId, $mess, $comment['created_time'], $likes_count, $comment['from']['id'], $comment['from']['name']);
				$this->cnt_inserted++;
			}
		}
	}

	// get another json with group feed
	private function getJson($cnt_attempts = 0)
	{
		if (!$this->nextPage || $this->nextPage == "")
		{
			$query = "/" . $this->gid . "/feed";
		}
		else
		{
			$query = str_replace('https://graph.facebook.com', '', $this->nextPage);
		}

		// trying download next json, it is possible to make 5 attempts per one query
		try
		{
			$result = $this->facebook->api($query);
		}
		catch (Exception $e)
		{
			echo "$cnt_attempts attempt per query ($query).";
			if ($cnt_attempts > 5)
			{
				echo "5 attempts per query ($query) are exhausted. Stopping whole script.";
				Debugger::log("$e on query: $query");
				$this->terminate();
			}
			echo 'Caught exception: ', $e->getMessage(), " ... repeating query ($query)\n";

			return $this->getJson(++$cnt_attempts);
		}

		if (isSet($result['paging']['next']))
		{
			$this->nextPage = $result['paging']['next'];
		}
		else
		{
			$this->nextPage = NULL;
		}

		$this->cnt_downloaded++;

		return $result;
	}

	// there are tags in messages like [tag], we wanna save them apart
	private function saveTags($message, $message_id)
	{
		$tags = $this->context->tags->extractTags($message);
		if (!$tags)
		{
			return;
		}
		foreach ($tags[0] as $tag)
		{
			$this->context->tags->saveTag($tag, $message_id);
		}
	}

}
