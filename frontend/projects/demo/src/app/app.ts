import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { CLIENT_VERSION } from '@apollo29/workflow-client';

@Component({
  imports: [RouterOutlet],
  selector: 'demo-root',
  templateUrl: './app.html',
})
export class App {
  protected readonly clientVersion = CLIENT_VERSION;
}
