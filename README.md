# duisburg_de_news_graphql_to_rss
Queries the GraphQL Endpoint of duisburg.de and makes the articles available as a Atom RSS feed for FreshRSS etc.

### This is created with the help of Claude AI

Get the [News of City of Duisburg](https://www.duisburg.de/news/aktuelle_news) as an RSS feed. Articles get loaded dynamicly with GraphQL [https://www.duisburg.de/api/graphql/](https://www.duisburg.de/api/graphql/). I wanted News-Feeds from the [sub-categories](https://www.duisburg.de/news/aktuelle_news#hier-finden-sie-die-news-sortiert-nach-kategorien), which are identified by groups and categories. They can be manually added to the `server.js` to make other feeds work, which did not interest me when creating.

```
  stadtentwicklung: { groups: ['8678'], categories: ['1912', '2030'] },
  stadtverwaltung:  { groups: ['8678'], categories: ['1912', '2032'] },
  verkehr:          { groups: ['8678'], categories: ['1912', '2041'] },
  umwelt:           { groups: ['8678'], categories: ['1912', '2027'] },
```

The `node.js` project is the server that does the heavy lifting, fetching, converting and outputting the feed via http. It acts as an adapter / sidecar for my FreshRSS instance in my docker-compose stack. `compose.yaml` is an incomplete example. 
