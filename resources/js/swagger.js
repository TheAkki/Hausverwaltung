import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

const container = document.getElementById('swagger-ui');

SwaggerUIBundle({
    url: container.dataset.specUrl,
    domNode: container,
    persistAuthorization: true,
});
