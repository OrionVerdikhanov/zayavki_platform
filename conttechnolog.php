<?php
$title = "📞 Контакты технолога";
$description = "Подать заявку можно по телефону X (XXXX) XXX XX XX.";
$buttonText = "📲 Позвонить технологу";
$phoneNumber = "tel:+7XXXXXXXXXX"; // Замени на реальный номер телефона
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Контакты технолога</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, #e3ffe7, #d9e7ff);
            background-size: 200% 200%;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            animation: backgroundAnimation 15s ease infinite;
        }

        .contact-block {
            background-color: #ffffff;
            padding: 40px;
            max-width: 450px;
            text-align: center;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            transition: transform 0.3s ease;
        }

        .contact-block:hover {
            transform: translateY(-10px);
        }

        .contact-block h2 {
            font-size: 22px;
            font-weight: 700;
            color: #34495e;
            margin-bottom: 15px;
        }

        .contact-block p {
            font-size: 17px;
            color: #555;
            line-height: 1.5;
        }

        .contact-button {
            display: inline-block;
            margin-top: 25px;
            padding: 12px 24px;
            font-size: 16px;
            color: #ffffff;
            text-decoration: none;
            background: linear-gradient(90deg, #11998e, #38ef7d);
            border-radius: 30px;
            box-shadow: 0 4px 12px rgba(56, 239, 125, 0.4);
            transition: transform 0.2s ease;
        }

        .contact-button:hover {
            transform: scale(1.05);
        }

        @keyframes backgroundAnimation {
            0%, 100% { background-position: 0 50%; }
            50% { background-position: 100% 50%; }
        }
    </style>
</head>
<body>
    <div class="contact-block">
        <h2><?php echo $title; ?></h2>
        <p><?php echo $description; ?></p>
        <a href="<?php echo $phoneNumber; ?>" class="contact-button"><?php echo $buttonText; ?></a>
    </div>
</body>
</html>
