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
