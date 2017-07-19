module.exports = function () {

    const pg = require('pg');

    // using credentials for Heroku-hosted PostgreSQL DB
    console.log('Connecting...');
    var pgClient = new pg.Client({
        user: "qcjnvylighdjod",
        password: "4412f62ecfc5fddf5f159d87e33e8480d2438b67370091dd29efb60eb6b84a11",
        database: "daka2f1gn3kb3j",
        port: 5432,
        host: "ec2-50-17-217-166.compute-1.amazonaws.com",
        ssl: true
    });

    pgClient.connect(function(err) {
        if(err) {
            console.log('Error!');
            console.log(err);
        } else {
            console.log('Connected!');

            pgClient.query('LISTEN events');

            pgClient.on('notification', function(data) {
                console.log('Heard something!');
                console.log('...');
                console.log(data.payload);

                // hitting Heroku PHP endpoint with our data payload
                var https = require("https");
                var options = {
                    hostname: 'tbc-schoology-prod.herokuapp.com',
                    port: 443,
                    path: '/',
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Content-Length': Buffer.byteLength(data.payload)
                    }
                };
                var req = https.request(options, function(res) {
                    console.log('Status: ' + res.statusCode);
                    console.log('Headers: ' + JSON.stringify(res.headers));
                    res.setEncoding('utf8');
                    res.on('data', function(body) {
                        console.log('Body: ' + body);
                    });
                });
                req.on('error', function(e) {
                    console.log('problem with request: ' + e.message);
                });

                // write data to request body to send
                req.write(data.payload);
                req.end();
            });
        }
    });
    
};
