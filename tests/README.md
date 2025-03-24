# How to run the tests locally on MacOS


1. Install the following dependencies:
	* docker: `brew install docker`
	* composer: `brew install composer`
	* node.js: `brew install node`

1. Ensure that host networking is enabled in Docker settings under `Resources > Network`.

1. Run the following command to install a Selenium server with a Chromium browser:
   ```bash
   docker run -d --shm-size="2g" --net=host --name="selenium-chromium" selenium/standalone-chromium:latest
   ```

1. Install the project dependencies:
   ```bash
   composer run-script packages-install
   npm install
   ```

1. Copy the file `tests/.dist.env` to `tests/.env`. Update the values as needed (shouldn't be needed).

1. Create your database fixture:
   ```bash
   npm run tests:export-db
   ```

1. Run the following command to run the tests:
   ```bash
   npm run tests:run
   ```
   
