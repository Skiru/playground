FROM python:3.14-alpine@sha256:05b2b8b732ecd268fee8727a369f936f022d1321b59befd13c30ede22769dcdc

ARG OCI_SOURCE=https://github.com/Skiru/playground
ARG OCI_REVISION
ARG OCI_CREATED
ARG OCI_VERSION
LABEL org.opencontainers.image.source=$OCI_SOURCE \
    org.opencontainers.image.revision=$OCI_REVISION \
    org.opencontainers.image.created=$OCI_CREATED \
    org.opencontainers.image.version=$OCI_VERSION \
    org.opencontainers.image.title="family-places-cf-access-validator" \
    org.opencontainers.image.description="FamilyPlaces Cloudflare Access token validator service"

WORKDIR /app

COPY tools/cf-access-validator/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY tools/cf-access-validator/app.py ./app.py

EXPOSE 8080
USER nobody:nogroup

HEALTHCHECK --interval=5s --timeout=3s --retries=3 \
  CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://localhost:8080/healthz')"]

CMD ["python", "app.py"]
