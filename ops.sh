mkdir -p /root/.fnsec && chmod 700 /root/.fnsec
[ -f /root/.fnsec/priv.pem ] || openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out /root/.fnsec/priv.pem 2>/dev/null
chmod 600 /root/.fnsec/priv.pem
openssl pkey -in /root/.fnsec/priv.pem -pubout | base64 -w0; echo
