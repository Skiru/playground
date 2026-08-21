FROM python:3.12-alpine@sha256:d09d15e60962ca365d1cd544a48773bac9d33f2fb1b00f2aa0deec78ade7dc31

WORKDIR /app

COPY tools/cf-access-validator/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY tools/cf-access-validator/app.py ./app.py

EXPOSE 8080
USER nobody:nogroup

HEALTHCHECK --interval=5s --timeout=3s --retries=3 \
  CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8080/healthz')"]

CMD ["python", "app.py"]
