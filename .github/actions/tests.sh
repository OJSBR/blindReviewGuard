#!/bin/bash

set -e

npx cypress run  --headless --browser chrome  --config '{"specPattern":["plugins/generic/blindReviewGuard/cypress/tests/functional/*.cy.js"]}'
