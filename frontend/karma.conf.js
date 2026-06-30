// Karma-Konfiguration für CI: stellt einen ChromeHeadlessCI-Launcher mit
// --no-sandbox bereit (nötig in Container-/CI-Umgebungen).
// Lokal funktioniert weiterhin der Default (`ng test`); diese Datei wird nur
// per `--karma-config=karma.conf.js` (siehe CI) genutzt.
module.exports = function (config) {
  config.set({
    basePath: '',
    frameworks: ['jasmine', '@angular-devkit/build-angular'],
    plugins: [
      require('karma-jasmine'),
      require('karma-chrome-launcher'),
      require('@angular-devkit/build-angular/plugins/karma'),
    ],
    reporters: ['progress'],
    browsers: ['ChromeHeadlessCI'],
    customLaunchers: {
      ChromeHeadlessCI: {
        base: 'ChromeHeadless',
        flags: ['--no-sandbox', '--disable-gpu'],
      },
    },
    restartOnFileChange: false,
    singleRun: true,
  });
};
