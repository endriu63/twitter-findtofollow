<?php
/**
 * Author: Kyle Thielk (www.kylethielk.com)
 * License:
 * Copyright (c) 2013 Kyle Thielk
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
require_once('TwitterDriver.php');
/**
 * Controls the flow of the application. General execution:
 *
 * buildFriendIds();
 * buildFollowerIds();
 * buildFilteredFollowers();
 *
 * Class FTF_Filter
 */
class FTF_Filter extends FTF_TwitterDriver
{

    /**
     * @var FTF_FilterRequest
     */
    private $filterRequest;
    /**
     * @var Array Ids of potential friends.
     */
    private $potentialFriendIds;
    /**
     * @var timer Timer class.
     */
    private $timer;
    /**
     * @var Array of users that matched our filter criteria. These are the final resuts.
     */
    public $filteredUsers;

    /**
     * @param array $apiKeys Our twitter api keys.
     * @param FTF_FilterRequest $filterRequest .
     */
    public function __construct($apiKeys, $filterRequest)
    {
        parent::__construct($apiKeys, $filterRequest->twitterUsername);

        $this->filterRequest = $filterRequest;
        $this->timer = new timer;
        $this->timer->set_output(2);


    }

    /**
     * Populate our driver with a list of people we are currently following.
     */
    public function buildFriendIds()
    {
        $friendIds = $this->twitterFriendsIds($this->filterRequest->twitterUsername);

        $this->addLogMessage('You have ' . ($friendIds ? count($friendIds) : 0) . ' friends according to twitter.');

        $this->userData->mergeInFriendIds($friendIds);
        $this->userData->flushPrimaryUserData();
    }

    /**
     * Get a list of all followers of sourceUsername up to WebRequest->followerLimit.
     */
    public function buildFollowerIds()
    {

        $this->potentialFriendIds = $this->twitterFollowersIds($this->filterRequest->sourceUsername, $this->filterRequest->followerLimit);
    }

    /**
     * We don't care about people we are already following, remove them from the list of people
     * we are about to filter.
     */
    public function removeUsersAlreadyFollowed()
    {
        $before_remove_count = count($this->potentialFriendIds);
        $this->potentialFriendIds = array_diff($this->potentialFriendIds, $this->userData->friendIds);
        $after_remove_count = count($this->potentialFriendIds);

        $this->addLogMessage('Removed a total of ' . ($before_remove_count - $after_remove_count) . ' people because you are already following them.');
    }

    /**
     * Given an array of twitter userids, break out the ids we already have cached locally so that we don't have to fetch them from twitter.
     * @param $ids Array of twitter ids.
     * @return Object An object with two arrays, {cachedUserIds: [], freshUserIds: []}
     */
    public function breakIdsByCached($ids)
    {
        $returnObject = (Object)array();
        $returnObject->freshUserIds = array();
        $returnObject->cachedUserIds = array();

        foreach ($ids as $id)
        {
            if (in_array($id, $this->userData->cachedUserIds))
            {
                $returnObject->cachedUserIds[] = $id;
            }
            else
            {
                $returnObject->freshUserIds[] = $id;
            }
        }

        $this->addLogMessage('Pulling fresh profiles for ' . count($returnObject->freshUserIds) . ' users from twitter. Had ' . count($returnObject->cachedUserIds) . ' cached.');

        return $returnObject;
    }

    /**
     * Fetches full user objects from twitter.
     * @param $userIds array Twitter user id's to fetch data for.
     * @return array|mixed Array of user data.
     */
    private function fetchUserDataFromTwitter($userIds)
    {

        $users = $this->twitterUsersLookup($userIds);

        //Write new data to cache.
        foreach ($users as $user)
        {
            $friend = new FTF_Friend($user);
            $this->userData->writeUserToCache($friend);
        }
        $this->userData->flushUserListCache();


        return $users;
    }

    /**
     * Once buildFriendIds and buildFollowerIds have been called, call this function
     * to filter the results. FTF_Filter->filteredUsers will hold the results.
     */
    public function buildFilteredFollowers()
    {
        $this->removeUsersAlreadyFollowed();

        $this->filteredUsers = array();

        $offset = 0;
        $limit = 100;


        while ((($offset + $limit) <= $this->filterRequest->followerLimit || $offset == 0) && ($offset) <= count($this->potentialFriendIds))
        {
            //Get subset of user ids to fetch full profiles for, twitter limits this call to 100 per call.
            $ids = array_slice($this->potentialFriendIds, $offset, $limit);


            //Get a list of fresh and cached ids, so we don't fetch profiles for people we already have
            $idObject = $this->breakIdsByCached($ids);

            //Get new and cached user data, and merge it so we can filter.
            $newUsers = $this->fetchUserDataFromTwitter($idObject->freshUserIds);
            $cachedFollowers = $this->userData->fetchCachedUsers($idObject->cachedUserIds);
            $unfilteredUsers = array_merge($newUsers, $cachedFollowers);

            $usersToAdd = $this->filterUsers($unfilteredUsers);
            $this->filteredUsers = array_merge($this->filteredUsers, $usersToAdd);

            //Update offset and possibly limit for next go around in loop
            $offset = $offset + $limit;
            if (($offset + $limit) > $this->filterRequest->followerLimit)
            {
                $limit = max($this->filterRequest->followerLimit - $offset, 1);
            }

        }


    }

    /**
     * Generates log message.
     * @return string Log Message.
     */
    public function generateLog()
    {
        $message = parent::generateLog();
        $message .= $this->generateTimerStats();
        return $message;

    }

    /**
     * Generates and returns the HTML for the filtered user results.
     * @return string HTML.
     */
    public function generateHtml()
    {
        $html = '
            <div class="all-checkbox">
                <input type="checkbox" id="selectAllCheckbox" onclick="FindToFollow.checkAllClicked(event);">All
            </div>
            <div class="follow-btn">
                <div id="followBtn" onclick="FindToFollow.openFollowPopup();" class="blue-button">Start Following (<span id="selectedCount">0</span>)</div>
            </div>
            <br />';
        $counter = 1;


        foreach ($this->filteredUsers as $user)
        {
            $html = $html . '<table width="500" class="user-table" id="userRow' . $user->id . '" onclick="FindToFollow.userTableRowClicked(event);">
                <tr>
                <td valign="top" class="number-td">
                    ' . $counter . '.
                </td>
                <td valign="middle" class="picture-td">
                    <img src="' . $user->profile_image_url . '" />
                </td>
                <td valign="middle" class="description-td">
                    <a href="http://www.twitter.com/' . $user->screen_name . '" target="_blank">' . $user->name . ' (@<span id="username' . $user->id . '">' . $user->screen_name . '</span>)</a><br />
                    <p>' . $user->description . '</p>
                    Friends/Following : <strong>' . $user->friends_count . '</strong> &nbsp;&nbsp;&nbsp;&nbsp; Followers: <strong>' . $user->followers_count . '</strong>
                </td>
                <td class="checkbox-td">
                    <input type="checkbox" name="checked" id="checked" value="' . $user->id . '" class="row-checkbox" />
                </td>
                </tr>
                </table>';
            $counter++;
        }

        if (count($this->filteredUsers) < 1)
        {
            $html = 'No Results Found!';
        }
        return $html;
    }

    /**
     * Given a list of users, filter based on the constraints set forth in the request.
     * @param $users Array of users.
     * @return array Filtered users that made the cut.
     */
    private function filterUsers($users)
    {
        $keywordArray = explode(',', $this->filterRequest->keywords);
        if ($keywordArray && count($keywordArray) == 1 && empty($keywordArray[0]))
        {
            $keywordArray = null;
        }
        $filteredUsers = array();

        foreach ($users as $user)
        {
            //$filteredUsers[] = $user;
            $addUser = true;

            if (!empty($this->filterRequest->minimumFriends) && $user->friends_count < $this->filterRequest->minimumFriends)
            {
                $addUser = false;
            }
            if ($addUser && !empty($this->filterRequest->maximumFriends) && $user->friends_count > $this->filterRequest->maximumFriends)
            {
                $addUser = false;
            }
            if (!empty($this->filterRequest->minimumFollowers) && $user->followers_count < $this->filterRequest->minimumFollowers)
            {
                $addUser = false;
            }
            if ($addUser && !empty($this->filterRequest->maximumFollowers) && $user->followers_count > $this->filterRequest->maximumFollowers)
            {
                $addUser = false;
            }
            if ($addUser && $this->filterRequest->friendToFollowerRatio == FTF_FilterRequest::FOLLOWERS_GREATER_THAN_FRIENDS && $user->followers_count < $user->friends_count)
            {
                $addUser = false;
            }
            if ($addUser && $this->filterRequest->friendToFollowerRatio == FTF_FilterRequest::FRIENDS_GREATER_THAN_FOLLOWERS && $user->followers_count > $user->friends_count)
            {
                $addUser = false;
            }

            if (isset($keywordArray) && count($keywordArray) > 0)
            {
                $keywordMatch = false;

                foreach ($keywordArray as $keyword)
                {
                    $keyword = trim($keyword);
                    $position = stripos($user->description, $keyword);
                    if ($position !== false)
                    {
                        $user->description = str_ireplace($keyword, '<strong>' . $keyword . '</strong>', $user->description);
                        $keywordMatch = true;
                    }
                }

                if (!$keywordMatch)
                {
                    $addUser = false;
                }
            }

            if ($addUser)
            {
                $filteredUsers[] = $user;
            }

        }

        return $filteredUsers;
    }

    /**
     * Generates a string of timer details.
     */
    public function generateTimerStats()
    {
        $this->timer->add_cp('Complete');

        ob_start();
        $this->timer->showme();
        $string = ob_get_contents();
        ob_end_clean();

        return $string;

    }


}

?>