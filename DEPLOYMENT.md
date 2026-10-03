# Muskan flight API deployment

The public website calls two same-origin PHP endpoints:

- `api/search-flights.php` creates a Duffel offer request.
- `api/offer.php` refreshes a selected offer before the traveller contacts Muskan.

The Duffel access token must never be committed or placed inside the public website directory. On the cPanel server, create `/home/technicacom/.config/muskan/duffel.php` with permissions `600`:

```php
<?php
return ['access_token' => 'duffel_live_REPLACE_ME'];
```

A `duffel_test_...` token can be used while validating the integration. Test results must not be presented as real fares. Activate the Duffel account and replace it with a live token before launch.

