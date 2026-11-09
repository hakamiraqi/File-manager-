# The AMC connector: Auto Multiple Choice (free software for paper MCQ exams) behind a small HTTP
# API, for the exam system to drive. The same image runs on the hosted server (beside the exam
# system's containers) or on a hall's own Linux server.
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 LC_ALL=C.UTF-8
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      auto-multiple-choice auto-multiple-choice-common \
      texlive-xetex texlive-latex-extra texlive-fonts-recommended texlive-lang-arabic texlive-lang-european \
      fonts-arabeyes fonts-dejavu-core fonts-noto-core \
      poppler-utils ghostscript imagemagick python3 sqlite3 ca-certificates man-db \
 && rm -rf /var/lib/apt/lists/* \
 && useradd -m -u 1500 amc \
 && mkdir -p /data && chown amc:amc /data

COPY server.py /app/server.py
USER amc
WORKDIR /data
ENV AMC_DATA=/data AMC_PORT=8080
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD python3 -c "import urllib.request,sys; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=4)" || exit 1
CMD ["python3", "/app/server.py"]
