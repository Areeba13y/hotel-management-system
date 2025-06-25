<?php
session_start();

// Backend PHP - process booking
$message = "";
$messageClass = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database connection
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "silver_peak";

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    if (isset($_POST['check_availability'])) {
        // First step: Check availability only
        $checkin = $_POST['checkin'];
        $checkout = $_POST['checkout'];
        $room_type = $_POST['room_type'];
        
        // Map form room types to database room types
        $roomTypeMap = [
            'Standard Room' => 'single',
            'Family Room' => 'double',
            'Suite Room' => 'suite'
        ];
        $db_room_type = $roomTypeMap[$room_type] ?? $room_type;

        // Validate dates
        $today = date('Y-m-d');
        if ($checkin < $today) {
            $message = "Check-in date cannot be in the past.";
            $messageClass = "error";
        } elseif ($checkin >= $checkout) {
            $message = "Check-out date must be after check-in date.";
            $messageClass = "error";
        } else {
            // Check for available rooms
            $sql = "SELECT id FROM rooms WHERE room_type = ?
                    AND id NOT IN (
                        SELECT room_id FROM bookings
                        WHERE NOT (checkout <= ? OR checkin >= ?)
                    )
                    LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sss", $db_room_type, $checkin, $checkout);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                $message = "Sorry, no $room_type rooms are available for the selected dates.";
                $messageClass = "error";
            } else {
                // Room available - store in session
                $room = $result->fetch_assoc();
                $_SESSION['available_room'] = $room['id'];
                $_SESSION['checkin'] = $checkin;
                $_SESSION['checkout'] = $checkout;
                $_SESSION['room_type'] = $room_type;
                
                $message = "$room_type is available from $checkin to $checkout. Please complete your booking details.";
                $messageClass = "success";
            }
            $stmt->close();
        }
    } elseif (isset($_POST['complete_booking'])) {
        // Second step: Complete booking with customer details
        if (!isset($_SESSION['available_room'])) {
            $message = "Please check availability first.";
            $messageClass = "error";
        } else {
            // Sanitize and assign POST variables
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            
            $room_id = $_SESSION['available_room'];
            $checkin = $_SESSION['checkin'];
            $checkout = $_SESSION['checkout'];
            $room_type = $_SESSION['room_type'];

            // Insert booking record
            $insert = $conn->prepare("INSERT INTO bookings (name, email, phone, checkin, checkout, room_id) VALUES (?, ?, ?, ?, ?, ?)");
            $insert->bind_param("sssssi", $name, $email, $phone, $checkin, $checkout, $room_id);
            
            if ($insert->execute()) {
                $booking_id = $insert->insert_id;

// Calculate payment amount using JOIN to get room price
$price_sql = "SELECT r.price_per_night 
             FROM bookings b
             JOIN rooms r ON b.room_id = r.id
             WHERE b.id = ?";
$stmt_price = $conn->prepare($price_sql);
$stmt_price->bind_param("i", $booking_id);
$stmt_price->execute();
$price_result = $stmt_price->get_result();
$price_row = $price_result->fetch_assoc();
$price_per_night = $price_row['price_per_night'];
$stmt_price->close();

                $date1 = new DateTime($checkin);
                $date2 = new DateTime($checkout);
                $nights = $date2->diff($date1)->days;
                $total_amount = $price_per_night * $nights;

                // Insert payment record
                $pay_stmt = $conn->prepare("INSERT INTO payments (booking_id, amount) VALUES (?, ?)");
                $pay_stmt->bind_param("id", $booking_id, $total_amount);
                $pay_stmt->execute();
                $pay_stmt->close();

                $message = "Booking successful for a $room_type room from $checkin to $checkout.<br>";
                $message .= "Total amount: Rs. $total_amount.<br>Thank you, $name!";
                $message .= "<br><br>Your booking reference is: #$booking_id";
                $messageClass = "success";
                
                // Store booking ID in session for cancellation
                $_SESSION['last_booking_id'] = $booking_id;
                $_SESSION['booking_email'] = $email;
                
                // Clear session variables
                unset($_SESSION['available_room']);
                unset($_SESSION['checkin']);
                unset($_SESSION['checkout']);
                unset($_SESSION['room_type']);
            } else {
                $message = "Error processing your booking. Please try again.";
                $messageClass = "error";
            }
            $insert->close();
        }
    } elseif (isset($_POST['cancel_booking'])) {
        // Cancel booking
        $booking_id = $_POST['booking_id'];
        $email = trim($_POST['cancel_email']);
        
// Verify booking exists with this email using JOIN
$verify_sql = "SELECT b.id, p.id as payment_id
              FROM bookings b
              LEFT JOIN payments p ON b.id = p.booking_id
              WHERE b.id = ? AND b.email = ?";
$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("is", $booking_id, $email);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            // Delete payment first (due to foreign key constraint)
            $delete_payment = $conn->prepare("DELETE FROM payments WHERE booking_id = ?");
            $delete_payment->bind_param("i", $booking_id);
            $delete_payment->execute();
            $delete_payment->close();
            
            // Then delete booking
            $delete_booking = $conn->prepare("DELETE FROM bookings WHERE id = ?");
            $delete_booking->bind_param("i", $booking_id);
            
            if ($delete_booking->execute()) {
                $message = "Booking #$booking_id has been successfully cancelled.";
                $messageClass = "success";
                
                // Clear last booking ID from session if it's the one being cancelled
                if (isset($_SESSION['last_booking_id']) && $_SESSION['last_booking_id'] == $booking_id) {
                    unset($_SESSION['last_booking_id']);
                    unset($_SESSION['booking_email']);
                }
            } else {
                $message = "Error cancelling booking. Please try again.";
                $messageClass = "error";
            }
            $delete_booking->close();
        } else {
            $message = "No booking found with that ID and email combination.";
            $messageClass = "error";
        }
        $verify_stmt->close();
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Hotel Silverpeak - Luxury Hotel</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <style>
    /* Reset and base styles */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Montserrat', sans-serif;
      color: #34495e;
      line-height: 1.6;
      position: relative;
    }
    
    .background-image {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-image: url('https://tse2.mm.bing.net/th?id=OIP.uJhT60DAXwkobmbjLzxx3gHaE7&pid=Api&P=0&h=220');
      background-size: cover;
      background-position: center;
      opacity: 0.2;
      z-index: -1;
    }
    
    /* Header styles */
    header {
      background-color: rgba(255, 255, 255, 0.9);
      padding: 20px 5%;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      position: sticky;
      top: 0;
      z-index: 100;
    }
    
    .logo {
      display: flex;
      align-items: center;
    }
    
    .logo-icon {
      font-family: 'Playfair Display', serif;
      font-size: 2.5rem;
      font-weight: bold;
      color: #2c3e50;
      margin-right: 10px;
    }
    
    .logo h1 {
      font-size: 1.2rem;
      font-weight: 600;
      letter-spacing: 0.2rem;
      color: #2c3e50;
    }
    
    .logo h2 {
      font-size: 1.2rem;
      font-weight: 400;
      letter-spacing: 0.2rem;
      color: #7f8c8d;
      margin-left: 10px;
    }
    
    nav ul {
      display: flex;
      list-style: none;
    }
    
    nav ul li {
      margin-left: 30px;
    }
    
    nav ul li a {
      text-decoration: none;
      color: #2c3e50;
      font-weight: 500;
      font-size: 0.9rem;
      letter-spacing: 0.1rem;
      transition: color 0.3s;
    }
    
    nav ul li a:hover {
      color: #27ae60;
    }
    
    /* Hero section */
    .hero {
      height: 80vh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      padding: 0 20px;
    }
    
    .hero h1 {
      font-family: 'Playfair Display', serif;
      font-size: 3rem;
      font-weight: 400;
      color: #2c3e50;
      margin-bottom: 10px;
    }
    
    .hero h2 {
      font-family: 'Playfair Display', serif;
      font-size: 4rem;
      font-weight: 700;
      color: #2c3e50;
      margin-bottom: 30px;
    }
    
    .hero-divider {
      width: 100px;
      height: 3px;
      background-color: #27ae60;
      margin: 20px 0;
    }
    
    .hero-subtext {
      font-size: 1.2rem;
      max-width: 600px;
      color: #7f8c8d;
    }
    
    /* Rooms section */
    .rooms {
      padding: 100px 5%;
      background-color: #fff;
    }
    
    .rooms h2 {
      font-family: 'Playfair Display', serif;
      font-size: 2.5rem;
      text-align: center;
      margin-bottom: 50px;
      color: #2c3e50;
    }
    
    .room-list {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 30px;
    }
    
    .room-card {
      flex: 1;
      min-width: 300px;
      background: #fff;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s, box-shadow 0.3s;
    }
    
    .room-card:hover {
      transform: translateY(-10px);
      box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
    }
    
    .room-card img {
      width: 100%;
      height: 250px;
      object-fit: cover;
    }
    
    .room-card h3 {
      font-family: 'Playfair Display', serif;
      font-size: 1.5rem;
      padding: 20px 20px 10px;
      color: #2c3e50;
    }
    
    .room-card p {
      padding: 0 20px 20px;
      color: #7f8c8d;
    }
    
    /* Booking form */
    .booking-form {
      padding: 80px 5%;
      background-color: #f9f9f9;
    }
    
    .booking-form form {
      background: #fff;
      padding: 40px;
      max-width: 800px;
      margin: 0 auto;
      border-radius: 10px;
      box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
    }
    
    .form-group {
      margin-bottom: 0;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      letter-spacing: 0.05em;
      color: #2c3e50;
      font-size: 0.9rem;
    }
    
    .form-group input,
    .form-group select {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #ddd;
      border-radius: 5px;
      font-size: 16px;
      transition: border-color 0.3s ease;
      font-family: 'Montserrat', sans-serif;
    }
    
    .form-group input:focus,
    .form-group select:focus {
      border-color: #27ae60;
      outline: none;
    }
    
    .check-availability {
      grid-column: 1 / -1;
      background-color: #27ae60;
      color: white;
      padding: 15px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 700;
      font-size: 18px;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      transition: background-color 0.3s ease;
      font-family: 'Montserrat', sans-serif;
    }
    
    .check-availability:hover {
      background-color: #219150;
    }
    
    .complete-booking {
      grid-column: 1 / -1;
      background-color: #2c3e50;
      color: white;
      padding: 15px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 700;
      font-size: 18px;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      transition: background-color 0.3s ease;
      font-family: 'Montserrat', sans-serif;
    }
    
    .complete-booking:hover {
      background-color: #1a252f;
    }
    
    .message {
      max-width: 800px;
      margin: 20px auto;
      padding: 18px;
      border-radius: 8px;
      font-weight: 600;
      text-align: center;
      line-height: 1.4;
      font-size: 16px;
    }
    
    .message.success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    
    .message.error {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
    
    /* Cancel booking form */
    .cancel-booking-form {
      background: #fff;
      padding: 40px;
      max-width: 800px;
      margin: 40px auto;
      border-radius: 10px;
      box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
    }
    
    .cancel-booking-form h3 {
      font-family: 'Playfair Display', serif;
      font-size: 1.8rem;
      margin-bottom: 20px;
      color: #2c3e50;
      text-align: center;
    }
    
    .cancel-booking-form .form-group {
      margin-bottom: 20px;
    }
    
    .cancel-booking {
      background-color: #e74c3c;
      color: white;
      padding: 15px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 700;
      font-size: 18px;
      width: 100%;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 10px;
      transition: background-color 0.3s ease;
      font-family: 'Montserrat', sans-serif;
    }
    
    .cancel-booking:hover {
      background-color: #c0392b;
    }
    
    /* Contact section */
    .contact {
      padding: 100px 5%;
      background-color: #fff;
      text-align: center;
    }
    
    .contact h2 {
      font-family: 'Playfair Display', serif;
      font-size: 2.5rem;
      margin-bottom: 30px;
      color: #2c3e50;
    }
    
    .contact p {
      font-size: 1.1rem;
      margin-bottom: 10px;
      color: #7f8c8d;
    }
    
    .map-container {
      margin-top: 40px;
      border-radius: 10px;
      overflow: hidden;
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    /* Footer */
    footer {
      background-color: #2c3e50;
      color: #ecf0f1;
      padding: 40px 5%;
      text-align: center;
    }
    
    .footer-content {
      max-width: 1200px;
      margin: 0 auto;
    }
    
    .social-icons {
      display: flex;
      justify-content: center;
      gap: 20px;
      margin-bottom: 20px;
    }
    
    .social-icons a {
      color: #ecf0f1;
      font-size: 1.2rem;
      transition: color 0.3s;
    }
    
    .social-icons a:hover {
      color: #27ae60;
    }
    
    .copyright {
      font-size: 0.9rem;
      opacity: 0.8;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
      header {
        flex-direction: column;
        padding: 20px;
      }
      
      nav ul {
        margin-top: 20px;
      }
      
      nav ul li {
        margin: 0 10px;
      }
      
      .hero h1 {
        font-size: 2rem;
      }
      
      .hero h2 {
        font-size: 2.5rem;
      }
      
      .room-list {
        flex-direction: column;
      }
      
      .booking-form form,
      .cancel-booking-form {
        grid-template-columns: 1fr;
        padding: 20px;
      }
    }
  </style>
</head>
<body>
  <div class="background-image"></div>

  <header>
    <div class="logo">
      <div class="logo-icon">H</div>
      <h1>HOTEL</h1>
      <h2>SILVERPEAK</h2>
    </div>
    <nav>
      <ul>
        <li><a href="#">HOME</a></li>
        <li><a href="#rooms">ROOMS</a></li>
        <li><a href="#contact">CONTACT</a></li>
      </ul>
    </nav>
  </header>

  <main>
    <section class="hero">
      <h1>WELCOME TO</h1>
      <h2>HOTEL SILVERPEAK</h2>
      <div class="hero-divider"></div>
      <p class="hero-subtext">Welcome to Silver Peak, where comfort meets elegance.</p>
    </section>

    <section class="rooms" id="rooms">
      <h2>Our Rooms</h2>
      <div class="room-list">
        <div class="room-card">
          <img src="https://tse3.mm.bing.net/th?id=OIP.sOGMz7z8onnxDAYxPPwLuwHaE7&pid=Api&P=0&h=220" alt="Standard Room" />
          <h3>Standard Room</h3>
          <p>A cozy room with everything you need for a comfortable stay.  ₨3,000

</p>
        </div>

        <div class="room-card">
          <img src="https://tse3.mm.bing.net/th?id=OIP.ZsUhMA-CDw9RBRiD-TsjBgHaE8&pid=Api&P=0&h=220" alt="Family Room" />
          <h3>Family Room</h3>
          <p>A spacious room perfect for relaxing and enjoying time together.  ₨5,000.</p>
        </div>

        <div class="room-card">
          <img src="https://tse4.mm.bing.net/th?id=OIP.3ZJ-ejWydPrl1JkqkWl4IgHaDt&pid=Api&P=0&h=220" alt="Suite Room" />
          <h3>Suite Room</h3>
          <p>A stylish and luxurious room for a special and relaxing experience. ₨8,000</p>
        </div>
      </div>
    </section>

    <section class="booking-form">
      <?php if ($message): ?>
        <div class="message <?php echo $messageClass; ?>">
          <?php echo $message; ?>
        </div>
      <?php endif; ?>
      
      <?php if (!isset($_SESSION['available_room'])): ?>
        <!-- First step: Check availability -->
        <form action="" method="POST">
          <div class="form-group">
            <label for="checkin">CHECK-IN</label>
            <input type="date" id="checkin" name="checkin" required min="<?php echo date('Y-m-d'); ?>" />
          </div>
          <div class="form-group">
            <label for="checkout">CHECK-OUT</label>
            <input type="date" id="checkout" name="checkout" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" />
          </div>
          <div class="form-group">
            <label for="room_type">ROOM TYPE</label>
            <select id="room_type" name="room_type" required>
              <option value="Standard Room">Standard Room</option>
              <option value="Family Room">Family Room</option>
              <option value="Suite Room">Suite Room</option>
            </select>
          </div>
          <button type="submit" name="check_availability" class="check-availability">
            CHECK AVAILABILITY
            <i class="fas fa-arrow-right"></i>
          </button>
        </form>
      <?php else: ?>
        <!-- Second step: Complete booking -->
        <form action="" method="POST">
          <div class="form-group">
            <label for="name">NAME</label>
            <input type="text" id="name" name="name" required />
          </div>
          <div class="form-group">
            <label for="email">EMAIL</label>
            <input type="email" id="email" name="email" required />
          </div>
          <div class="form-group">
            <label for="phone">PHONE</label>
            <input type="tel" id="phone" name="phone" required />
          </div>
          <div class="form-group">
            <label>BOOKING DETAILS</label>
            <p>
              Room: <?php echo $_SESSION['room_type']; ?><br>
              Check-in: <?php echo $_SESSION['checkin']; ?><br>
              Check-out: <?php echo $_SESSION['checkout']; ?>
            </p>
          </div>
          <button type="submit" name="complete_booking" class="complete-booking">
            COMPLETE BOOKING
            <i class="fas fa-check"></i>
          </button>
        </form>
      <?php endif; ?>
      
      <!-- Cancel booking form -->
      <div class="cancel-booking-form">
        <h3>Cancel Booking</h3>
        <form action="" method="POST">
          <div class="form-group">
            <label for="booking_id">BOOKING REFERENCE</label>
            <input type="text" id="booking_id" name="booking_id" required 
                   placeholder="Enter your booking reference number"
                   value="<?php echo isset($_SESSION['last_booking_id']) ? $_SESSION['last_booking_id'] : ''; ?>" />
          </div>
          <div class="form-group">
            <label for="cancel_email">EMAIL USED FOR BOOKING</label>
            <input type="email" id="cancel_email" name="cancel_email" required 
                   placeholder="Enter the email you used for booking"
                   value="<?php echo isset($_SESSION['booking_email']) ? $_SESSION['booking_email'] : ''; ?>" />
          </div>
          <button type="submit" name="cancel_booking" class="cancel-booking">
            CANCEL BOOKING
            <i class="fas fa-times"></i>
          </button>
        </form>
      </div>
    </section>

    <section class="contact" id="contact">
      <h2>Contact Us</h2>
      <p>Email: contact@hotelsilverpeak.com</p>
      <p>Location: Rawalpindi, Pakistan</p>
      <div class="map-container">
        <iframe 
          src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3318.497538998287!2d73.05513981514216!3d33.565124980734796!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x38dfbf3f11e09bdb%3A0x33c64ff84f23cdbd!2sRawalpindi%2C%20Pakistan!5e0!3m2!1sen!2sus!4v1684684209426!5m2!1sen!2sus" 
          width="100%" height="300" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>
    </section>
  </main>

  <footer>
    <div class="footer-content">
      <div class="social-icons">
        <a href="#"><i class="fab fa-facebook-f"></i></a>
        <a href="#"><i class="fab fa-instagram"></i></a>
        <a href="#"><i class="fab fa-twitter"></i></a>
        <a href="#"><i class="fab fa-pinterest"></i></a>
      </div>
      <div class="copyright">
        © HOTEL SILVERPEAK - ALL RIGHTS RESERVED
      </div>
    </div>
  </footer>
</body>
</html>