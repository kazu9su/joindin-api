<?php

class Event_commentsController extends ApiController {
    public function handle(Request $request, $db) {
        // only GET is implemented so far
        if($request->getVerb() == 'GET') {
            return $this->getAction($request, $db);
        }
        return false;
    }

	public function getAction($request, $db) {
        $comment_id = $this->getItemId($request);

        // verbosity
        $verbose = $this->getVerbosity($request);

        // pagination settings
        $start = $this->getStart($request);
        $resultsperpage = $this->getResultsPerPage($request);

        $mapper = new EventCommentMapper($db, $request);
        if($comment_id) {
            $list = $mapper->getCommentById($comment_id, $verbose);
            if(false === $list) {
                throw new Exception('Comment not found', 404);
            }
            return $list;
        } 

        return false;
	}

    public function createComment($request, $db) {
        $comment = array();
        $comment['event_id'] = $this->getItemId($request);
        if(empty($comment['event_id'])) {
            throw new Exception(
                "POST expects a comment representation sent to a specific event URL",
                400
            );
        }
                            
        // no anonymous comments over the API
        if(!isset($request->user_id) || empty($request->user_id)) {
            throw new Exception('You must log in to comment');
        }
        $user_mapper = new UserMapper($db, $request);
        $users = $user_mapper->getUserById($request->user_id);
        $thisUser = $users['users'][0];

        $rating = $request->getParameter('rating', false);
        if(false === $rating) {
            throw new Exception('The field "rating" is required', 400);
        } elseif (false === is_numeric($rating) || $rating > 5) {
            throw new Exception('The field "rating" must be a number (1-5)', 400);
        }

        $commentText = $request->getParameter('comment');
        if(empty($commentText)) {
            throw new Exception('The field "comment" is required', 400);
        }

        // Get the API key reference to save against the comment
        $oauth_model = $request->getOauthModel($db);
        $consumer_name = $oauth_model->getConsumerName($request->getAccessToken());

        $comment['user_id'] = $request->user_id;
        $comment['comment'] = $commentText;
        $comment['rating'] = $rating;                    
        $comment['cname'] = $thisUser['full_name'];
        $comment['source'] = $consumer_name;

        // run it by akismet if we have it
        if (isset($this->config['akismet']['apiKey'], $this->config['akismet']['blog'])) {
            $spamCheckService = new SpamCheckService(
                $this->config['akismet']['apiKey'],
                $this->config['akismet']['blog']
            );
            $isValid = $spamCheckService->isCommentAcceptable(
                $comment,
                $request->getClientIP(),
                $request->getClientUserAgent()
            );
            if (!$isValid) {
                throw new Exception("Comment failed spam check", 400);
            }
        }

        $comment_mapper = new EventCommentMapper($db, $request);
        try {
            $new_id = $comment_mapper->save($comment);
        } catch (Exception $e) {
            // just throw this again but with a 400 status code
            throw new Exception($e->getMessage(), 400);
        }

        // Update the cache count for the number of event comments on this event
        $event_mapper = new EventMapper($db, $request);
        $event_mapper->cacheCommentCount($comment['event_id']);

        $uri = $request->base . '/' . $request->version . '/event_comments/' . $new_id;
        header("Location: " . $uri, NULL, 201);
        exit;
    }
}
