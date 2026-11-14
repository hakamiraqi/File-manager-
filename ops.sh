docker exec filenest-app php artisan migrate:status 2>/dev/null | grep koha
docker exec filenest-app php artisan library:koha-sync
docker exec filenest-app php artisan route:list --name=koha 2>/dev/null | grep -c koha
docker exec filenest-app php artisan schedule:list 2>/dev/null | grep koha
docker exec filenest-app php artisan tinker --execute='echo json_encode(App\Services\Library\Koha\KohaSettings::forPage())."\n"; echo "books=".App\Models\Library\Book::count()." loans_open=".App\Models\Library\Loan::whereNull("returned_at")->count()."\n";'
