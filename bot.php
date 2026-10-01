RewriteEngine On
RewriteBase /film/

# stream1.m3u8 -> bot.php?s=stream1
RewriteRule ^stream([0-9]+)\.m3u8$ bot.php?s=stream$1 [L,QSA]

# Diğer istekler bot.php'ye
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ bot.php [L,QSA]
