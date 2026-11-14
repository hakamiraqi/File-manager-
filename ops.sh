t() { echo "== $1"; curl -s -m 25 -o /tmp/sru.xml -w 'HTTP %{http_code} %{time_total}s %{size_download}B\n' "$2"; grep -o '<[a-z:]*numberOfRecords>[0-9]*' /tmp/sru.xml | head -1; grep -o 'tag="245"[^/]*<[a-z:]*subfield code="a">[^<]*' /tmp/sru.xml | head -1; }
t loc 'http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.isbn%3D9780323414920&maximumRecords=2&recordSchema=marcxml'
t loc-title 'http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=dc.title%3D%22oral%20and%20maxillofacial%20surgery%22&maximumRecords=2&recordSchema=marcxml'
t dnb 'https://services.dnb.de/sru/dnb?version=1.1&operation=searchRetrieve&query=num%3D9780323414920&recordSchema=MARC21-xml&maximumRecords=2'
t k10 'https://sru.k10plus.de/opac-de-627?version=1.1&operation=searchRetrieve&query=pica.isb%3D9780323414920&recordSchema=marcxml&maximumRecords=2'
t bnf 'https://catalogue.bnf.fr/api/SRU?version=1.2&operation=searchRetrieve&query=bib.isbn%20adj%20%229780323414920%22&recordSchema=unimarcxchange&maximumRecords=2'
t loc-ar 'http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.isbn%3D9789953683186&maximumRecords=2&recordSchema=marcxml'
which yaz-client || echo "no yaz"
