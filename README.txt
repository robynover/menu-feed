NYPL Labs menus feed project
Robyn Overstreet
======
This project was created for a coding challenge exercise for New York Public Library Labs, using their archive of New York City Restaurant menus that date back to the 1850s. NYPL Labs currently has an app that makes robust use of this data. I am not connected to that site or to NYPL labs. Check it out at http://menus.nypl.org/

======
REQUIREMENTS FOR THE CODING EXERCISE:

Using the MySQL dump, write the code for a service that expresses the 'menus' table as an Atom feed ordered by newest to oldest. The body of each entry in the feed should contain a list of the items on that menu. You may use any language and platform you wish, and there is plenty of room for interpreting these requirements as you wish but please write a short explanation of the choices you made.

======
per the instructions:
NOTES ON MY APPROACH AND PROCESS

One of the major challenges of this task was keeping the processing time down so that parsing a large dataset didn't take a prohibitively long time to load. Because of the circuitous route between venue names and dish names in the database, the query involved several joins, which impacted processing time. To mitigate this, I chose to use the pagination feature of the Atom specification, and broke up the feed into a series of pages, linked to each other through link tags at the top of the feed. 

The most sensible way to query the database seemed to be to start with the menus table, as menus where the central entity of the feed, and to create joins from there. Because not all the menus had menu items, and not all the menu items had prices, getting the correct information from the joins proved challenging. The best way I found was to concatenate the names of dishes in a string and bring it in with the menu row. From there, though, I also needed to pull in the prices for the dishes which had them. Because some price fields were null, it wasn't possible to concatenate the dishes and prices in the same string. Simply creating an additional concatenation string for prices wouldn't work because there was no guarantee that there would be the same number of dishes as prices, making it impossible to match up the two. In the end, I created two concatenated strings, one with the dish id and the dish name, and the other with the dish id and the price. I used PHP to parse the strings and match them up using the id numbers. I generally try to do as much of the sorting and grouping as possible on the database side, but it became apparent that some of it would have to be done in PHP, and this proved the most efficient option.

In PHP, I created a class called NYPLMenuFeed that produces the Atom feed using the built-in set of DOM classes. These classes manage memory more efficiency than writing XML by hand, and makes coding it less cumbersome. My class takes care of querying the database, parsing the concatenated strings for the menu items, and structuring the results in Atom format. The class constructor takes a page parameter that controls which subsection of the results to fetch. The class also allows the ability to set the number of records per page, with a default number of 100 records. 

The menu items in the feed, when they exist, appear in the content node of each entry element. Atom feeds often use XHTML inside the content node, but also permit use of alternate content types. I used XML here in order to provide semantic mark-up, with the idea that the feed would most likely be consumed as a web service, as opposed to with an RSS reader.

