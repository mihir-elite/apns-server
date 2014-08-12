Apple Push Notification Service PHP Server
===========


Built this server for my company and because it took me some weeks
to figure everything out, I decided to publish it for others to use.

The basic flow of this server is the following:

1. Some application lpush json jobs to Redis server on 127.0.0.1 into
'acme.apns' list
2. APNS-Server rpop json jobs from Redis
3. Parses and formats the jobs
4. Send the messages to Apple Push Notification Service on sandbox or
   production servers


Thre's a working example after the class source code:

1. Simply add your Redis endpoint (default is 127.0.0.1)
2. Add your certificates as 'apns-dev.pem' and 'apns-prod.pem' and the Entrust certificates 'entrust_root_certification_authority.pem' or 'entrust_2048_ca.cer'

Run as:

`php ApplePushNotificationServiceServer.php`
