<?php

require_once "vendor/autoload.php";

// Create app
$app = new Slim\App;

// Load configuration with dotenv
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

// Get container
$container = $app->getContainer();

// Register Twig component on container to use view templates
$container['view'] = function() {
    return new Slim\Views\Twig('views');
};

// Initialize database
$container['db'] = function() {
    return new PDO('sqlite:support.sqlite');
};

// Load and initialize MesageBird SDK
$container['messagebird'] = function() {
    return new MessageBird\Client(getenv('MESSAGEBIRD_API_KEY'));
};

// Handle incoming webhooks
$app->post('/webhook', function($request, $response) {
    // Read input sent from MessageBird
    $number = $request->getParsedBodyParam('originator');
    $text = $request->getParsedBodyParam('payload');

    // Find open ticket for number in our database
    $stmt = $this->db->prepare('SELECT * FROM tickets WHERE number = :number AND open = :open');
    $stmt->execute([ 'number' => $number, 'open' => (int)true ]);
    $ticket = $stmt->fetch();
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

    // Add inbound message to ticket
    $stmt = $this->db->prepare('INSERT INTO messages (ticket_id, direction, content) VALUES (:ticket_id, :direction, :content)');
    $stmt->execute([
        'ticket_id' => $id,
        'direction' => 'in',
        'content' => $text
    ]);

    // Return any response, MessageBird won't parse this
    return "OK";
});

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

// Process replies to tickets
$app->post('/reply', function($request, $response) {
    // Read form input
    $id = $request->getParsedBodyParam('id');
    $content = $request->getParsedBodyParam('content');

    // Fetch ticket from database to assert it exists and get number
    $stmt = $this->db->prepare('SELECT * FROM tickets t WHERE id = :id');
    $stmt->execute([ 'id' => $id ]);
    $ticket = $stmt->fetch();
    if ($ticket === false) {
        return "Ticket does not exist!";
    }    

    // Add an outbound message to the existing ticket
    $stmt = $this->db->prepare('INSERT INTO messages (ticket_id, direction, content) VALUES (:ticket_id, :direction, :content)');
    $stmt->execute([
        'ticket_id' => $id,
        'direction' => 'out',
        'content' => $content
    ]);

    // Send reply to customer
    $message = new MessageBird\Objects\Message;
    $message->originator = getenv('MESSAGEBIRD_ORIGINATOR');
    $message->recipients = [ $ticket['number'] ];
    $message->body = $content;
    try {
        $this->messagebird->messages->create($message);
    } catch (Exception $e) {
        error_log(get_class($e).": ".$e->getMessage());
    }

    // Return to previous page
    return $response->withRedirect('/admin');
});

// Start the application
$app->run();