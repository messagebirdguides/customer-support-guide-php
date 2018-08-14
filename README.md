# SMS Customer Support 
### ⏱ 30 min build time

## Why build SMS customer support? 

People love communicating in real time, regardless of whether its their friends or to a business. Real time support in a comfortable medium can create an excellent support experience that can help retain users for life. 

On the business side, Support teams need to organize communication with their customers, often using ticket systems to combine all messages for specific cases in a shared view for support agents.

In this MessageBird Developer Guide, we'll show you how to build a simple customer support system for SMS-based communication between consumers and companies, built in PHP.

Our sample application has the following features:

- Customers can send any message to a virtual mobile number (VMN) created and published by the company. Their message becomes a support ticket, and they receive an automated confirmation with a ticket ID for their reference.
- Any subsequent message from the same number is added to the same support ticket. There's no additional confirmation.
- Support agents can view all messages in a web view and reply to them.

## Getting Started

To run the sample application, you need to have PHP installed on your machine. If you're using a Mac, PHP is already installed. For Windows, you can [get it from windows.php.net](https://windows.php.net/download/). Linux users, please check your system's default package manager. You also need Composer, which is available from [getcomposer.org](https://getcomposer.org/download/), to install application dependencies like the [MessageBird SDK for PHP](https://github.com/messagebird/php-rest-api).

The source code is available in the [MessageBird Developer Guides GitHub repository](https://github.com/messagebirdguides/customer-support-guide-php) from which it can be cloned or downloaded into your development environment.

After saving the code, open a console for the download directory and run the following command, which downloads the Slim framework, MessageBird SDK and other dependencies defined in the `composer.json` file:

````bash
composer install
````

It's helpful to know the basics of the [Slim framework](https://packagist.org/packages/slim/slim) to follow along with the tutorial, but you should be able to get the gist of it also if your experience lies with other frameworks.

Our sample application uses a relational database to store tickets and messages. It is configured to use a single-file [SQLite](https://www.sqlite.org/) database, which is natively supported by PHP through PDO so that it works out of the box without the need to configure an external RDBMS like MySQL. Run the following helper command to create the `support.sqlite` file which contains an empty database with the required schema:

````bash
php init.php
````

## Prerequisites for Receiving Messages

### Overview

The support system receives incoming messages. From a high-level viewpoint, receiving with MessageBird is relatively simple: an application defines a _webhook URL_, which you assign to a number purchased on the MessageBird Dashboard using a flow. A [webhook](https://en.wikipedia.org/wiki/Webhook) is a URL on your site that doesn't render a page to users but is like an API endpoint that can be triggered by other servers. Every time someone sends a message to that number, MessageBird collects it and forwards it to the webhook URL, where you can process it.

### Exposing your Development Server with ngrok

One small roadblock when working with webhooks is the fact that MessageBird needs to access your application, so it needs to be available on a public URL. During development, you're typically working in a local development environment that is not publicly available. Thankfully this is not a big deal since various tools and services allow you to quickly expose your development environment to the Internet by providing a tunnel from a public URL to your local machine. One of the most popular tools is [ngrok](https://ngrok.com).

You can [download ngrok here for free](https://ngrok.com/download) as a single-file binary for almost every operating system, or optionally sign up for an account to access additional features.

You can start a tunnel by providing a local port number on which your application runs. We will run our PHP server on port 8080, so you can launch your tunnel with this command:

````bash
ngrok http 8080
````

After you've launched the tunnel, ngrok displays your temporary public URL along with some other information. We'll need that URL in a minute.

![ngrok](ngrok.png)

Another common tool for tunneling your local machine is [localtunnel.me](https://localtunnel.me), which you can have a look at if you're facing problems with ngrok. It works in virtually the same way but requires you to install [NPM](https://www.npmjs.com/) first.

### Getting an Inbound Number

A requirement for receiving messages is a dedicated inbound number. Virtual mobile numbers look and work similar like regular mobile numbers, however, instead of being attached to a mobile device via a SIM card, they live in the cloud, i.e., a data center, and can process incoming SMS and voice calls. MessageBird offers numbers from different countries for a low monthly fee. Here's how to purchase one:

1. Go to the [Numbers](https://dashboard.messagebird.com/en/numbers) section of your MessageBird account and click **Buy a number**.
2. Choose the country in which you and your customers are located and make sure the _SMS_ capability is selected.
3. Choose one number from the selection and the duration for which you want to pay now. ![Buy a number screenshot](buy-a-number.png)
4. Confirm by clicking **Buy Number**.

Awesome, you have set up your first virtual mobile number!

### Connecting the Number to a Webhook

So you have a number now, but MessageBird has no idea what to do with it. That's why you need to define a _Flow_ next that ties your number to your webhook. Here is one way to achieve that:

1. Go to the [Flow Builder](https://dashboard.messagebird.com/en/flow-builder) section of your MessageBird account. Under _Use a template_, you'll see a list of templates. Find the one named "Call HTTP endpoint with SMS" and click "Try this flow". ![Create Flow, Step 1](create-flow-1.png)
2. The flow contains two steps. On the first step, the trigger "Incoming SMS", tick the box next to your number and **Save**. ![Create Flow, Step 2](create-flow-2.png)
4. Click on the second step, "Forward to URL". Choose _POST_ as the method, copy the output from the `ngrok` command in the previous step and add `/webhook` to the end of it - this is the name of the route we use to handle incoming messages. Click **Save**. ![Create Flow, Step 3](create-flow-3.png)
5. Click **Publish** to activate your flow.

## Configuring the MessageBird SDK

The MessageBird SDK and an API key are not required to receive messages. However, since we want to send replies, we need to add and configure it. The SDK is listed as a dependency in `composer.json`:

````json
{
    "require" : {
        "messagebird/php-rest-api" : "^1.9.4"
        ...
    }
}
````

An application can access the SDK, which is made available through Composer autoloading, by creating an instance of the `MessageBird\Client` class. The constructor takes a single argument, your API key. For frameworks like Slim you can add the SDK to the dependency injection container:

````php
// Load and initialize MesageBird SDK
$container['messagebird'] = function() {
    return new MessageBird\Client(getenv('MESSAGEBIRD_API_KEY'));
};
````

As it's a bad practice to keep credentials in the source code, we load the API key from an environment variable using `getenv()`. To make the key available in the environment variable we need to initialize Dotenv and then add the key to a `.env` file.

Apart from `MESSAGEBIRD_API_KEY` we use another environment variable called `MESSAGEBIRD_ORIGINATOR` which contains the phone number used in our system, i.e., the VMN you just registered.

You can copy the `env.example` file provided in the repository to `.env` and then add your API key and phone number like this:

````env
MESSAGEBIRD_API_KEY=YOUR-API-KEY
MESSAGEBIRD_ORIGINATOR=+31970XXXXXXX
````

You can retrieve or create an API key from the [API access (REST) tab](https://dashboard.messagebird.com/en/developers/access) in the _Developers_ section of your MessageBird account.

## Receiving Messages

Now that the preparations for receiving messages are complete, we'll implement the `$app->post('/webhook')` route:

````php
// Handle incoming webhooks
$app->post('/webhook', function($request, $response) {
    // Read input sent from MessageBird
    $number = $request->getParsedBodyParam('originator');
    $text = $request->getParsedBodyParam('payload');
````

MessageBird sends a few fields for incoming messages. We're interested in two of them: the originator, which is the number that the message came from (tip: don't confuse it with the originator you configured, which is for _outgoing_ messages), and the payload, which is the content of the text message.

````php
    // Find open ticket for number in our database
    $stmt = $this->db->prepare('SELECT * FROM tickets WHERE number = :number AND open = :open');
    $stmt->execute([ 'number' => $number, 'open' => (int)true ]);
    $ticket = $stmt->fetch();
````

The number is used to look up the ticket with an SQL query. If none exists, we create a new ticket using SQL INSERT into the `tickets` table and send a confirmation message to the user with the ticket ID:

````php
    if ($ticket === false) {
        // Creating a new ticket
        $stmt = $this->db->prepare('INSERT INTO tickets (number, open) VALUES (:number, :open)');
        $stmt->execute([ 'number' => $number, 'open' => (int)true ]);
        $id = $this->db->lastInsertId();
        
        // Send a confirmation
        $message = new MessageBird\Objects\Message;
        $message->originator = getenv('MESSAGEBIRD_ORIGINATOR');
        $message->recipients = [ $number ];
        $message->body = "Thanks for contacting customer support! Your ticket ID is " . $id . ".";
        try {
            $this->messagebird->messages->create($message);
        } catch (Exception $e) {
            error_log(get_class($e).": ".$e->getMessage());
        }
    } else {
        $id = $ticket['id'];
    }
````

To send the confirmation message, we create a new `MessageBird\Objects\Message` object first. This object requires three attributes:
- Our configured originator, so that the receiver sees a reply from the number which they contacted in the first place. It is taken from the environment variable.
- A recipient array with the number from the incoming message so that the reply goes back to the right person.
- The body of the message, which contains the ticket ID.

Then, we call `messages->create()` on the SDK object and provide the message object as its only parameter. The API call is contained in a try-catch block, and any sending errors are written to the default error log.

So, what if a ticket already exists? In this case (our `else` block) we just read the ticket ID from it. The next block of code, which is executed in both cases, is responsible for adding a new message to the `messages` table with an SQL INSERT statement including a reference to the ticket ID:

````php
    // Add inbound message to ticket
    $stmt = $this->db->prepare('INSERT INTO messages (ticket_id, direction, content) VALUES (:ticket_id, :direction, :content)');
    $stmt->execute([
        'ticket_id' => $id,
        'direction' => 'in',
        'content' => $text
    ]);
````

Servers sending webhooks typically expect you to return a response with a default 200 status code to indicate that their webhook request was received, but they do not parse the response. Therefore we send the string _OK_ at the end of the route handler, independent of the case that we handled:

````php
    // Return any response, MessageBird won't parse this
    return "OK";
});
````

## Reading Messages

Customer support team members can view incoming tickets from an admin view. We have implemented a simple admin view in the `$app->get('/admin')` route:

````php
// Show tickets for customer support admin
$app->get('/admin', function($request, $response) {
    // Find all open tickets
    $stmt = $this->db->prepare('SELECT t.id, t.number, m.direction, m.content FROM tickets t JOIN messages m ON m.ticket_id = t.id WHERE t.open = :open');
    $stmt->execute([ 'open' => (int)true ]);
    $ticketsAndMessages = $stmt->fetchAll();

    // Group and format messages to tickets for easier display
    $tickets = [];
    foreach ($ticketsAndMessages as $tam) {
        if (!isset($tickets[$tam['id']])) {
            $tickets[$tam['id']] = [
                'id' => $tam['id'],
                'number' => $tam['number'],
                'messages' => [
                    [
                        'direction' => $tam['direction'],
                        'content' => $tam['content']
                    ]
                ]
            ];
        } else {
            $tickets[$tam['id']]['messages'][] = [
                'direction' => $tam['direction'],
                'content' => $tam['content']
            ];
        }
    }

    // Show a page with tickets
    return $this->view->render($response, 'admin.html.twig', [ 'tickets' => $tickets ]);
});
````

Inside this route, we use an SQL SELECT query with a JOIN over the `tickets` and `messages` table and then run a loop over the results to convert them into a nested array structure. This array structure is used by [Twig](https://twig.symfony.com/) to render the view from a template. This template is stored in `views/admin.handlebars`. Apart from the HTML that renders the documents, there is a small Javascript section in it that refreshes the page every 10 seconds. Thanks to this you can keep the page open and will receive messages automatically with only a small delay and without the implementation of Websockets.

## Replying to Messages

The admin template also contains a form for each ticket through which you can send replies. The implementation uses `messages->create()` analogous to the confirmation messages we're sending for new tickets. If you're curious about the details, you can look at the `$app->post('/reply')` implementation route in `index.php`.

## Testing the Application

Check again that you have set up your number correctly with a flow that forwards incoming messages to a tunneling URL and that the tunnel is still running. Remember, whenever you start a fresh tunnel with the `ngrok` command, you'll get a new URL, so you have to update the flow accordingly.

To start the application you have to enter another command, but your existing console window is already busy running your tunnel. Therefore you need to open another one. On a Mac you can press _Command_ + _Tab_ to open a second tab that's already pointed to the correct directory. With other operating systems you may have to resort to manually open another console window. Either way, once you've got a command prompt, type the following to start the application:

````bash
php -S 0.0.0.0:8080 index.php
````

Open http://localhost:8080/admin in your browser. You should see an empty list of tickets. Then, take out your phone, launch the SMS app and send a message to your virtual mobile number. Around 10-20 seconds later, you should see your message in the browser! Amazing! Try again with another message which will be added to the ticket, or send a reply.

## Nice work!

You now have a running SMS Customer Support application!

You can now use the flow, code snippets and UI examples from this tutorial as an inspiration to build your own SMS Customer Supoport system. Don't forget to download the code from the [MessageBird Developer Guides GitHub repository](https://github.com/messagebirdguides/customer-support-guide-php).

## Next steps

Want to build something similar but not quite sure how to get started? Please feel free to let us know at support@messagebird.com, we'd love to help!