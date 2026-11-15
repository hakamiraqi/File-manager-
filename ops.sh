ID=$(docker exec -u www-data filenest-app php artisan tinker --execute='echo App\Models\Library\Book::min("id");' | tail -1)
curl -s -m 20 -w "\ncite %{http_code}\n" "https://files.azoclinic.duckdns.org/library/books/$ID/cite" | head -c 400; echo
docker exec -u www-data filenest-app php artisan tinker --execute='$d = app(App\Services\Library\DoiLookup::class)->find("10.5281/zenodo.7998389"); echo "datacite: ".($d["title"] ?? "none")."\n";'
