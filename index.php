<?php
/*
* Copyright (C) 2013 Google Inc.
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
*      http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/
//  Author: Jenny Murphy - http://google.com/+JennyMurphy

require_once 'config.php';
require_once 'mirror-client.php';
require_once 'google-api-php-client/src/Google_Client.php';
require_once 'google-api-php-client/src/contrib/Google_MirrorService.php';
require_once 'util.php';

$client = get_google_api_client();

// Authenticate if we're not already
if (!isset($_SESSION['userid']) || get_credentials($_SESSION['userid']) == null) {
  header('Location: ' . $base_url . '/oauth2callback.php');
  exit;
} else {
  $client->setAccessToken(get_credentials($_SESSION['userid']));
}

// A glass service for interacting with the Mirror API
$mirror_service = new Google_MirrorService($client);

// But first, handle POST data from the form (if there is any)
switch ($_POST['operation']) {
  case "insertItem":
    $new_timeline_item = new Google_TimelineItem();
    $new_timeline_item->setText($_POST['message']);

    $notification = new Google_NotificationConfig();
    $notification->setLevel("DEFAULT");
    $new_timeline_item->setNotification($notification);

    if (isset($_POST['imageUrl']) && isset($_POST['contentType'])) {
      insertTimelineItem($mirror_service, $new_timeline_item,
        $_POST['contentType'], file_get_contents($_POST['imageUrl']));
    } else {
      insertTimelineItem($mirror_service, $new_timeline_item, null, null);
    }

    $message = "Timeline Item inserted!";
    break;
  case "insertItemWithAction":
    $new_timeline_item = new Google_TimelineItem();
    $new_timeline_item->setText("What did you have for lunch?");

    $notification = new Google_NotificationConfig();
    $notification->setLevel("DEFAULT");
    $new_timeline_item->setNotification($notification);

    $menuItems = array();

    // A couple of built in menu items
    $menuItem = new Google_MenuItem();
    $menuItem->setAction("READ_ALOUD");
    array_push($menuItems, $menuItem);
    $new_timeline_item->setSpeakableText("What did you eat? Bacon?");

    $menuItem = new Google_MenuItem();
    $menuItem->setAction("SHARE");
    array_push($menuItems, $menuItem);

    // A custom menu item
    $customMenuItem = new Google_MenuItem();
    $customMenuValue = new Google_MenuValue();
    $customMenuValue->setDisplayName("Drill Into");
    $customMenuValue->setIconUrl($service_base_url . "/static/images/drill.png");

    $customMenuItem->setValues(array($customMenuValue));
    $customMenuItem->setAction("CUSTOM");
    // This is how you identify it on the notification ping
    $customMenuItem->setId("safe-for-later");
    array_push($menuItems, $customMenuItem);

    $new_timeline_item->setMenuItems($menuItems);

    insertTimelineItem($mirror_service, $new_timeline_item, null, null);

    $message = "Inserted a timeline item you can reply to";
    break;
  case "insertTimelineAllUsers":
    $credentials = list_credentials();
    if (count($credentials) > 10) {
      $message = "Found " . count($credentials) . " users. Aborting to save your quota.";
    } else {
      foreach ($credentials as $credential) {
        $user_specific_client = get_google_api_client();
        $user_specific_client->setAccessToken($credential['credentials']);

        $new_timeline_item = new Google_TimelineItem();
        $new_timeline_item->setText("Did you know cats have 167 bones in their tails? Mee-wow!");

        $user_specific_mirror_service = new Google_MirrorService($user_specific_client);

        insertTimelineItem($user_specific_mirror_service, $new_timeline_item, null, null);
      }
      $message = "Sent a cat fact to " . count($credentials) . " users.";
    }
    break;
  case "insertSubscription":
    $message = subscribeToNotifications($mirror_service, $_POST['subscriptionId'],
      $_SESSION['userid'], $base_url . "/notify.php");
    break;
  case "deleteSubscription":
    $message = $mirror_service->subscriptions->delete($_POST['subscriptionId']);
    break;
  case "insertContact":
    insertContact($mirror_service, $_POST['id'], $_POST['name'],
        $base_url . "/static/images/chipotle-tube-640x360.jpg");
    $message = "Contact inserted. Enable it on MyGlass.";
    break;
  case "deleteContact":
    deleteContact($mirror_service, $_POST['id']);
    $message = "Contact deleted.";
    break;
}

//Load cool stuff to show them.
$timeline = $mirror_service->timeline->listTimeline(array('maxResults'=>'3'));
try {
  $contact = $mirror_service->contacts->get("php-quick-start");
} catch (Exception $e) {
  // no contact found. Meh
  $contact = null;
}
$subscriptions = $mirror_service->subscriptions->listSubscriptions();
$timelineSubscriptionExists = false;
$locationSubscriptionExists = false;
foreach ($subscriptions['items'] as $subscription) {
  if ($subscription['id'] == 'timeline') {
    $timelineSubscriptionExists = true;
  } elseif ($subscription['id'] == 'location') {
    $locationSubscriptionExists = true;
  }
}

?>
<!doctype html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Glassware Starter Project</title>
  <link href="./static/bootstrap/css/bootstrap.min.css" rel="stylesheet" media="screen">
  <style>
    .button-icon { max-width: 75px; }
    .tile {
      border-left: 1px solid #444;
      padding: 5px;
      list-style: none;
    }
    .btn { width: 100%; }
  </style>
</head>
<body>
<div class="navbar navbar-inverse navbar-fixed-top">
  <div class="navbar-inner">
    <div class="container">
      <a class="brand" href="#">Glassware Starter Project: PHP Edition</a>
      <div class="nav-collapse collapse">
        <form class="navbar-form pull-right" action="signout.php" method="post">
          <button type="submit" class="btn">Sign out</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="container">

  <div class="hero-unit">
    <h1>Your Recent Timeline</h1>
    <?php if ($message != "") { ?>
    <span class="label label-warning">Message: <?= $message ?> </span>
    <?php } ?>

    <div style="margin-top: 5px;">
      <?php foreach ($timeline['items'] as $timeline_item) { ?>
      <ul class="span3 tile">
        <li><strong>ID: </strong> <?= $timeline_item['id'] ?>
        </li>
        <li>
          <strong>Text: </strong> <?= $timeline_item['text'] ?>
        </li>
        <li>
          <strong>Attachments: </strong>
          <?php
          if (isset($timeline_item['attachments'])) {
            $attachments = $timeline_item['attachments'];
            foreach ($attachments as $attachment) { ?>
                <img src="<?= $base_url .
                    '/attachment-proxy.php?timeline_item_id='.
                    $timeline_item['id'].'&attachment_id='.$attachment['id'] ?>" />
            <?php
            }
          }
          ?>
        </li>

      </ul>
      <?php } ?>
    </div>
    <div style="clear:both;"></div>
  </div>

  <div class="row">
    <div class="span4">
      <h2>Timeline</h2>

      <p>When you first sign in, this Glassware inserts a welcome message. Use
        these controls to insert more items into your timeline. Learn more about
        the timeline APIs
        <a href="https://developers.google.com/glass/timeline">here</a></p>


      <form method="post">
        <input type="hidden" name="operation" value="insertItem">
        <textarea name="message">Hello World!</textarea><br/>
        <button class="btn" type="submit">The above message</button>
      </form>

      <form method="post">
        <input type="hidden" name="operation" value="insertItem">
        <input type="hidden" name="message"
               value="Chipotle says hi!">
        <input type="hidden" name="imageUrl" value="<?= $base_url .
            "/static/images/chipotle-tube-640x360.jpg" ?>">
        <input type="hidden" name="contentType" value="image/jpeg">

        <button class="btn" type="submit">A picture
          <img class="button-icon" src="<?= $base_url .
             "/static/images/chipotle-tube-640x360.jpg" ?>">
        </button>
      </form>
      <form method="post">
        <input type="hidden" name="operation" value="insertItemWithAction">
        <button class="btn" type="submit">A card you can reply to</button>
      </form>
      <hr>
      <form method="post">
        <input type="hidden" name="operation" value="insertTimelineAllUsers">
        <button class="btn" type="submit">A card to all users</button>
      </form>
    </div>

  <div class="span4">
    <h2>Contacts</h2>
    <p>By default, this project inserts a single contact that accepts
      all content types. Learn more about contacts
      <a href="https://developers.google.com/glass/contacts">here</a>.</p>

      <?php if ($contact == null) { ?>
      <form class="span3"method="post">
        <input type="hidden" name="operation" value="insertContact">
        <input type="hidden" name="iconUrl" value="<?= $base_url .
            "/static/images/chipotle-tube-640x360.jpg" ?>">
        <input type="hidden" name="name" value="PHP Quick Start">
        <input type="hidden" name="id" value="php-quick-start">
        <button class="btn" type="submit">Insert PHP Quick Start Contact</button>
      </form>
      <?php } else { ?>
      <form class="span3" method="post">
        <input type="hidden" name="operation" value="deleteContact">
        <input type="hidden" name="id" value="php-quick-start">
        <button class="btn" type="submit">Delete PHP Quick Start Contact</button>
      </form>
    <? } ?>
    </div>

    <div class="span4">
      <h2>Subscriptions</h2>

  <p>By default a subscription is inserted for changes to the
    <code>timeline</code> collection. Learn more about subscriptions
    <a href="https://developers.google.com/glass/subscriptions">here</a></p>

  <p class="label label-info">Note: Subscriptions require SSL. <br>They will
    not work on localhost.</p>

  <?php if ($timelineSubscriptionExists) { ?>
    <form method="post">
      <input type="hidden" name="subscriptionId" value="timeline">
      <input type="hidden" name="operation" value="deleteSubscription">
      <button class="btn" type="submit">Unsubscribe from
        timeline updates</button>
    </form>
  <? } else { ?>
    <form method="post">
      <input type="hidden" name="operation" value="insertSubscription">
      <input type="hidden" name="subscriptionId" value="timeline">
      <button class="btn" type="submit">Subscribe to timeline updates</button>
    </form>
  <?php } ?>

  <?php if ($locationSubscriptionExists) { ?>
    <form method="post">
      <input type="hidden" name="subscriptionId" value="location">
      <input type="hidden" name="operation" value="deleteSubscription">
      <button class="btn" type="submit">Unsubscribe from
        location updates</button>
    </form>
  <?php } else { ?>
    <form method="post">
      <input type="hidden" name="operation" value="insertSubscription">
      <input type="hidden" name="subscriptionId" value="locations">
      <button class="btn" type="submit">Subscribe to location updates</button>
    </form>
  <?php } ?>
    </div>
  </div>
</div>

<script
    src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
<script src="/static/bootstrap/js/bootstrap.min.js"></script>
</body>
</html>
