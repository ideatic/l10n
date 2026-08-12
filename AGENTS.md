This project is ideatic/l10n, a tool to extract and manage translations from source code.

When writing PHP code, follow this best practices:

### **General Coding Standards & Style**

- **Adhere to PSR-1 and PSR-12:** All code must strictly follow the PSR-1 (Basic Coding Standard) and PSR-12 (Extended Coding Style) guidelines.
- **Leverage Modern PHP Features:** Actively use features from PHP 7, 8 and newer, including but not limited to, type declarations, the null coalescing operator (`??`), and arrow functions for concise callbacks.
- **Use English for Documentation:** All comments and documentation must be written in clear and concise English to ensure code maintainability and clarity. 
- **Use short echo syntax in templates:** Use the short echo syntax (`<?=`) in HTML templates.
- **Prefer First-class callable syntax:** For callables, prefer using First-class callable (`CallableExpr(...)`) that is more accessible to static analysis.
- **Prefer interpolation:** When embedding variables in strings, prefer interpolation (e.g., `"Hello, {$name}"`) over concatenation (e.g., `"Hello, " . $name`). Always use curly braces for variable interpolation to avoid ambiguity.

### **Object-Oriented Programming & Design**

- **Embrace SOLID Principles:** Structure all object-oriented code according to the five SOLID principles (Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, and Dependency Inversion) to build scalable and maintainable applications.
- **Properly Encapsulate Code:** Use `public`, `private`, and `protected` access modifiers to control the visibility of properties and methods, effectively encapsulating the internal state of objects.
- **Prefix Private Properties:** All private properties and methods must be prefixed with an underscore (`_`). For example: `private $_connection;`.

### **Type Safety & Static Analysis**

- **Enable Strict Types:** Every PHP file must begin with `declare(strict_types=1);` to enforce strict type checking and prevent unexpected type coercion.
- **Mandate Explicit Type Declarations:** All function parameters and return types must have explicit type declarations to improve code clarity and reduce runtime errors.
- **Static Analysis Friendly:** Ensure full compatibility with tools like PHPStan. Use native type hints everywhere. Use PHPDoc *only* to specify generics, array shapes, or types that native hints cannot express. Avoid redundant docblocks (e.g., no `@return void` when `: void` exists). You can find the available PHPStan PHPDoc Types at https://phpstan.org/writing-php-code/phpdoc-types
- **Validate with PHPStan:** All new and modified code must be checked and pass validation with PHPStan before being merged.

### **Error Handling**

- **Use Exceptions for Errors:** Employ exceptions for error handling. Avoid returning `false` or `null` to indicate an error state, as this can lead to ambiguous and error-prone code.
