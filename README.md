#LockLizard Enterprise V4 API Wrapper

A wrapper for LockLizard Enterprise v4 API, to make the API slightly more paletable.

##Features
- Parses LockLizard's utterly bizzare response format, returning associative arrays
- Automatically chunks requests that contain more than 200 IDs
- Fakes some helpful methods not in the LockLizard API, for instance replacing access rules instead of just adding or removing

##To do (PRs welcome)
- Throw exceptions on errors
- Write tests
- Improve send and sendPost methods?
- Possibly add some more methods via screen scraping of the admin? It's not possible to get the number of activations via the API...

##Usage

    <?php

    $api = new LockLizardAdminAPI($server_url, $username, $password);

    $customers = $api->listCustomer('email', 'john@example.com');

    // $customers = [
    //     'status' => 'OK', 'data' => [
    //         'id' => 'xxx',
    //         'name' => 'xxx',
    //         'email' => 'xxx',
    //         'company_name' => 'xxx',
    //         'valid_from' => 'xxx',
    //         'expires_at' => 'xxx',
    //         'licenses' => 'xxx',
    //         'active' => 'xxx',
    //         'registered' => 'xxx'
    //     ]
    // ];
    ?>

##Methods
Check out the code - it's pretty well commented.

##Licence
Released under [MIT license](http://opensource.org/licenses/MIT)
