# Развёртывание PHP-сайта на Ubuntu 22.04 LTS

Полная пошаговая инструкция «с нуля»: от установки ОС до запуска вашего PHP-приложения с MariaDB и PHP 8.2.  
Предполагается, что вы храните:

- **SQL-дамп** в `/root/dbzayavki/u2611449_zayavki-2.sql`  
- **Файлы проекта** (включая `vendor/`) вы скопируете вручную в `/var/www/html/`

---

## 📋 Предварительные требования

- Чистая Ubuntu 22.04 LTS  
- Пользователь с `sudo`-правами  
- Публичный IP сервера (например `89.110.97.21`)  
- Доступ в интернет для установки пакетов  

---

## 🚀 Шаг 1. Обновление системы и установка утилит

```bash
sudo apt update && sudo apt upgrade -y
sudo timedatectl set-timezone Europe/Amsterdam
sudo apt install -y software-properties-common ca-certificates \
                    lsb-release apt-transport-https curl \
                    vim unzip ufw
```

---

## 🔥 Шаг 2. Настройка файрвола (UFW)

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp      # HTTP
sudo ufw allow 443/tcp     # HTTPS
sudo ufw --force enable
sudo ufw status
```

---

## 🖥️ Шаг 3. Установка и настройка Apache

```bash
sudo apt install -y apache2
sudo a2enmod rewrite
# Сделать index.php приоритетным
sudo sed -i 's/DirectoryIndex .*/DirectoryIndex index.php index.html/' \
    /etc/apache2/mods-enabled/dir.conf
sudo systemctl restart apache2
```

---

## 🗄️ Шаг 4. Установка и «жесткая» настройка MariaDB

```bash
sudo apt install -y mariadb-server
sudo mysql_secure_installation <<EOF

y
StrongRootPass!
StrongRootPass!
y
y
y
y
EOF
```

- Задайте пароль `root` (мы использовали `StrongRootPass!`, замените на свой)  
- Удалите анонимных пользователей, запретите удалённый `root`, удалите тестовую БД  

---

## 🐘 Шаг 5. Установка PHP 8.2 и модулей

```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.2 libapache2-mod-php8.2 \
                    php8.2-mysql php8.2-xml \
                    php8.2-mbstring php8.2-curl php8.2-zip
sudo a2dismod php8.1
sudo a2enmod php8.2
sudo systemctl restart apache2
```

Проверьте CLI-версию:

```bash
php -v   # должно показать PHP 8.2.x
```

Для проверки создайте `/var/www/html/info.php`:

```php
<?php phpinfo();
```

Откройте в браузере `http://<IP_сервера>/info.php`.

---

## 📂 Шаг 6. Развёртывание проекта (ручное копирование)

Скопируйте **все** файлы вашего сайта (PHP-скрипты, папку `vendor/`, `style.css` и т.д.) в корень веб-директории:

```bash
# Пример, если файлы лежат в /root/dbzayavki/:
sudo rm -rf /var/www/html/*
sudo cp -r /root/dbzayavki/* /var/www/html/
```

---

## 🔐 Шаг 7. Права доступа

```bash
sudo chown -R www-data:www-data /var/www/html
sudo find /var/www/html -type d -exec chmod 755 {} \;
sudo find /var/www/html -type f -exec chmod 644 {} \;
```

---

## 🛠️ Шаг 8. Создание базы, пользователя и импорт дампа

```bash
sudo mysql <<EOF
CREATE DATABASE IF NOT EXISTS \`u2611449_zayavki\`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'u2611449_zayavki'@'localhost'
  IDENTIFIED BY 'u2611449_zayavki';
GRANT ALL PRIVILEGES ON \`u2611449_zayavki\`.* TO 'u2611449_zayavki'@'localhost';
FLUSH PRIVILEGES;
EOF

sudo mysql u2611449_zayavki < /root/dbzayavki/u2611449_zayavki-2.sql
```

---

## ⚙️ Шаг 9. Настройка конфигурации вашего приложения

Откройте в `/var/www/html/` файл конфигурации (например `config.php`) и укажите параметры подключения:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'u2611449_zayavki');
define('DB_PASS', 'u2611449_zayavki');
define('DB_NAME', 'u2611449_zayavki');
// далее — подключение автозагрузчика, функций и остальной код
```

---

## 🔄 Шаг 10. Перезапуск Apache и проверка

```bash
sudo systemctl restart apache2
```

1. В браузере откройте `http://<IP_сервера>/` — должна загрузиться главная страница вашего сайта.  
2. Если видите ошибку или белый экран — проверьте логи Apache:

```bash
sudo tail -n 50 /var/log/apache2/error.log
```

---

## 🔒 (Опционально) Шаг 11. Настройка HTTPS с Let’s Encrypt

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com
```

---

🎉 **Поздравляем!**  
Теперь ваш Ubuntu 22.04 сервер полностью настроен:

- Apache 2.4 + PHP 8.2  
- MariaDB с настроенной БД и пользователем  
- PHP-сайт в `/var/www/html/` запущен и доступен по HTTP/HTTPS  
