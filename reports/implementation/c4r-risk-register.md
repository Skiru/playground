# C4R Media Pipeline - Risk Register

## Risk Table

| ID | Description | Severity | Impact | Mitigation Strategy | Status |
| --- | --- | --- | --- | --- | --- |
| RSK-1 | Transient S3/Storage network failures during upload/download | Medium | Medium | Retry strategies configured with exponential backoff on Symfony Messenger async queues. | Active |
| RSK-2 | Large concurrent image upload causing high memory/CPU load on VPS | Low | High | Validation rules strictly limit files to 10 per request, 12MB per file, 50MB total, and 40 megapixels maximum resolution before processing begins. | Active |
| RSK-3 | Processing of corrupt/malicious image payloads causing buffer overflow | High | High | Strict server-side MIME type verification using `finfo` and `getimagesizefromstring` without error suppression. Private source files are kept isolated in `/private` directories with no public URLs. | Active |
