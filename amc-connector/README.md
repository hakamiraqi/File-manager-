# AMC connector

[Auto Multiple Choice](https://www.auto-multiple-choice.net/) (free software for paper multiple-choice
exams) behind a small HTTP API, so the exam system can build the named bubble sheets, take the scans
and bring back the marks — without AMC on the teacher's computer.

## On the hosted server

Nothing to do: `deploy/vps/docker-compose.yml` builds and runs it as `examnest-amc`, and
`deploy/vps/deploy.sh` gives it a random `AMC_TOKEN` (the app reaches it at `http://amc:8080`).

## On a hall's own Linux server (a separate step)

Any Linux machine on the hall's network with Docker:

```sh
docker build -t examnest-amc amc-connector
docker run -d --name examnest-amc --restart unless-stopped -p 8080:8080 \
  -e AMC_TOKEN="$(head -c 30 /dev/urandom | base64 | tr -dc A-Za-z0-9)" \
  -v examnest-amc:/data examnest-amc
docker exec examnest-amc printenv AMC_TOKEN      # the key to type in the exam system
```

Then in the exam system: Monitor → Central server and halls → «Auto Multiple Choice server»: the
address (`http://<that machine>:8080`) and the key. «Save and test» checks it.

## API

All but `/health` need `Authorization: Bearer $AMC_TOKEN`. See the docstring of `server.py`.

## Check

`.github/workflows/amc-connector.yml` builds the image and runs `test/e2e.py`: real AMC builds the
sheets, the test fills chosen boxes where AMC's layout says they are, sends the pages back as scans,
and compares AMC's exported ticked boxes with what was filled.
