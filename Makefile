SHELL := /bin/bash

.PHONY: qa qa-validate qa-security qa-style qa-analyse qa-test regression coverage archive test-matrix queue-round-trip sqlite-queue-smoke stand-fast stand-full stand-archive stand-mutation stand ci-up ci-push ci-open ci-down

qa:
	@composer qa

qa-validate:
	@composer qa:validate

qa-security:
	@composer qa:security

qa-style:
	@composer qa:style

qa-analyse:
	@composer qa:analyse

qa-test:
	@composer qa:test

regression:
	@composer regression

coverage:
	@composer qa:coverage

archive:
	@composer qa:archive

test-matrix:
	@php tools/test-matrix.php

queue-round-trip:
	@vendor/bin/pest tests/Integration/QueueRoundTripTest.php --compact

sqlite-queue-smoke:
	@RICK_TEST_SQLITE_QUEUE_PROFILE=1 vendor/bin/pest tests/Integration/SqliteConcurrentQueueSelectionTest.php --compact

stand-fast:
	@composer stand-fast

stand-full:
	@composer stand-full

stand-archive:
	@composer stand-archive

stand-mutation:
	@composer stand-mutation

stand:
	@composer stand

ci-up:
	@./local-ci/ci up

ci-push:
	@./local-ci/ci push

ci-open:
	@./local-ci/ci open

ci-down:
	@./local-ci/ci down
