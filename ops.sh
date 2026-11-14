t() { echo "== $1"; curl -s -m 25 -o /tmp/sru.xml -w 'HTTP %{http_code} %{time_total}s %{size_download}B\n' "$2"; grep -o '<[a-z:]*numberOfRecords>[0-9]*' /tmp/sru.xml | head -1; tr -d '\n' < /tmp/sru.xml | grep -o '<[a-z:]*datafield[^>]*tag="245".\{0,300\}' | head -1 | sed 's/<[^>]*>/ /g' | cut -c1-160; }
t loc 'http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.isbn%3D9780132350884&maximumRecords=2&recordSchema=marcxml'
t loc10 'http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.isbn%3D0132350882&maximumRecords=2&recordSchema=marcxml'
t k10 'https://sru.k10plus.de/opac-de-627?version=1.1&operation=searchRetrieve&query=pica.isb%3D9780132350884&recordSchema=marcxml&maximumRecords=2'
t dnb 'https://services.dnb.de/sru/dnb?version=1.1&operation=searchRetrieve&query=num%3D9780132350884&recordSchema=MARC21-xml&maximumRecords=2'
t loc-dental 'http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.isbn%3D9780323479005&maximumRecords=2&recordSchema=marcxml'
t k10-dental 'https://sru.k10plus.de/opac-de-627?version=1.1&operation=searchRetrieve&query=pica.isb%3D9780323414920&recordSchema=marcxml&maximumRecords=2'
