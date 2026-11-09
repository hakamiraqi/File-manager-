FROM python:3.12-slim
RUN pip install --no-cache-dir openpyxl==3.1.5 \
 && useradd -m -u 1600 survey && mkdir -p /data && chown survey:survey /data
COPY --chown=survey:survey . /app
USER survey
ENV SURVEY_DATA=/data SURVEY_PORT=8080 PYTHONUNBUFFERED=1
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=4)" || exit 1
CMD ["python3", "/app/server.py"]
