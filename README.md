# et-helper

This plugin helps with debugging things through various tools and have some necessary things supported.

### Features
1. Divi JSON Parser
2. Prettier Format of Divi Builder Raw JSON.
3. Free Form CSS Detector.
4. Element CSS Detector.
5. Invalid HTML Detector.
6. Invalid CSS Detector.
7. Show/Hide/Delete Debug Display.
8. SVG Support.
9. Stats of the Layout.
10. Reset and Import Feature with two predefined SQL files.

#### Divi JSON Parser & Prettier Format
This feature helps convert the raw Divi Builder JSON to a prettier format to make the debugging process easier to find out the necessary information quickly.

<img width="1728" height="880" alt="image" src="https://github.com/user-attachments/assets/65d609f7-5cb3-4e6f-badd-d1e82aacf905" />

###
It has a nested structure added to the code to understand which section/row belongs which modules. It works like a tree now. We can easily understand where a section/row/coluomn/module is started/ended with a tag-format.

<img width="961" height="748" alt="image" src="https://github.com/user-attachments/assets/847b908f-4d70-4f71-a43f-0252e71f0057" />

#### Free-Form CSS Detector
This feature collects all the Free-Form CSS from the entire layout elements and put them into a single tab in the plugin settings. It is helpful to find out any CSS quickly from that list. It also list out the CSS of responsive devices.

All blocks have a nested breadcrumb to quickly find out the specific element the CSS belongs to.

<img width="986" height="838" alt="image" src="https://github.com/user-attachments/assets/6d75f412-b367-4d9f-beef-58a9890c2ac3" />

### Element CSS Detector
It is the same as the Free-Form CSS. It collects all elements' CSS separately into a single place.

<img width="988" height="829" alt="image" src="https://github.com/user-attachments/assets/97334851-164f-4bcc-a8c6-d06f6d1bf8c6" />

#### Invalid HTML Detector
It searches all text fields across the entire layout's all modules to find out any invalid/unclosed/missing tags, etc HTML markup that can break the layout or cause unnecessary rendering issue.

With this detector, corrupted codes can be easily found using the nested breadcrumb.

<img width="961" height="838" alt="image" src="https://github.com/user-attachments/assets/2804834a-53d1-4897-a4b0-52488a233ed1" />

#### Invalid CSS Detector
It searches for all invalid/corrupted CSS codes across the layout elements - Free-Form CSS and Element CSS fields. Any invalid codes found are listed in a single place with the nested breadcrumb to quickly navigate to that instance.

<img width="960" height="840" alt="image" src="https://github.com/user-attachments/assets/2e93880c-e7b0-48e2-939e-875b2e65e981" />

#### Show/Hide/Delete Debug
This tools helps turning on/off the debug mode by one click and also the debug log file can be deleted entirely.

#### SVG Support
With this plugin, we can upload SVG images to the website since WordPress doesn't support it by default.

#### Stats & Others
Also, it shows the number of sections/rows/columns available on a layout. Additionally, the formatted JSON file can be downloaded. There is a search field that scrolls to the targeted element quickly.

#### Sample File For Testing
[plugin-test-layout.json.zip](https://github.com/user-attachments/files/27137993/plugin-test-layout.json.zip)

### Reset & Import Feature
This can be used to reset the website database and import with two SQL files. One for fresh installation and another with data. <kbd>fresh-sql.sql</kbd> and <kbd>with-data.sql</kbd>. 

The credentials will remain the same because once the website is initiated, the existing SQL files will automatically be regenerated with the current user's credentials and wit the current website URL.

<img width="1350" height="837" alt="image" src="https://github.com/user-attachments/assets/21891ce2-1196-4923-8e4e-4093f6230385" />
